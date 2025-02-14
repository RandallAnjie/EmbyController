<?php

namespace app\api\controller;

use app\api\model\EmbyDeviceModel;
use app\api\model\EmbyUserModel;
use app\api\model\MediaHistoryModel;
use app\api\model\TelegramModel;
use app\api\model\UserModel;
use app\media\model\SysConfigModel as SysConfigModel;
use think\facade\Cache;
use think\facade\Config;
use app\BaseController;
use Telegram\Bot\Api;
use think\facade\Request;
use WebSocket\Client;

class Media extends BaseController
{
    public function index()
    {
        $time = time();
        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'time' => $time
            ]
        ]);
    }

    public function ping()
    {
        $time = time();
        return json([
            'code' => 200,
            'msg' => 'pong',
            'data' => [
                'time' => $time
            ]
        ]);
    }

    public function webhook()
    {
        try {
            // 获取get参数
            $data = Request::get();
            if (isset($data['key']) && $data['key'] == Config::get('media.crontabKey')) {
                $data = Request::param();
                if (isset($data['Event']) && $data['Event'] != '' && isset($data['User']) && $data['User'] != '') {
                    $userModel = new UserModel();
                    $embyUserModel = new EmbyUserModel();
                    $user = null;
                    $embyUser = $embyUserModel->where('embyId', $data['User']['Id'])->find();
                    if ($embyUser) {
                        $user = $userModel->where('id', $embyUser['userId'])->find();
                    }

                    $session = $data['Session']??null;

                    if ($session && $user) {
                        // 如果有$session['Client']，则判断是不是在允许客户端列表中
                        if (isset($session['Client']) && $session['Client'] != '') {

                            $session['Client'] = urldecode($session['Client']);

                            $clientList = Config::get('media.clientList');
                            if (!in_array($session['Client'], $clientList)) {
                                $flag = false;

                                $clientBlackList = Config::get('media.clientBlackList');
                                for ($i = 0; $i < count($clientBlackList); $i++) {
                                    if (stripos($session['Client'], $clientBlackList[$i]) !== false || stripos($clientBlackList[$i], $session['Client']) !== false) {
                                        $flag = true;
                                        break;
                                    }
                                }

                                if ($flag) {
                                    sendTGMessageToGroup('用户' . ($user['nickName']??$user['userName']) . '正在使用黑名单客户端: ' . $session['Client'] . '，开始封禁用户');
                                    $embyUserId = $data['User']['Id'];
                                    $url = Config::get('media.urlBase') . 'Users/' . $embyUserId . '/Policy?api_key=' . Config::get('media.apiKey');
                                    $data = [
                                        'IsDisabled' => true
                                    ];
                                    $ch = curl_init($url);
                                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                        'accept: */*',
                                        'Content-Type: application/json'
                                    ]);
                                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                                    $response = curl_exec($ch);
                                    if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200 || curl_getinfo($ch, CURLINFO_HTTP_CODE) == 204) {
                                        $mediaMaturityTemplate = '您的' . Config::get('app.app_name') . '账号已经禁止使用。';
                                        sendTGMessage($user['id'], '您的' . Config::get('app.app_name') . '账号已经禁止使用。');
                                        sendEmail($user['email'], '账号已经禁止使用 - ' . Config::get('app.app_name'), $mediaMaturityTemplate);
                                    }

                                    $user->rCoin = 0;
                                    $user->authority = -1;
                                    $user->save();

                                    return true;
                                } else {
                                    $cacheKey = 'embyDeviceNotInWhite_' . $data['User']['Id'];
                                    $cacheValue = Cache::get($cacheKey);
                                    if (!$cacheValue) {
                                        Cache::set($cacheKey, 1, 300);
                                        sendTGMessageToGroup('用户' . ($user['nickName']??$user['userName']) . '正在使用不在建议客户端列表中的客户端: ' . $session['Client'] . ' (本消息5分钟内不再重复发送)');
                                    } else {
                                        Cache::set($cacheKey, $cacheValue+1, 300);
                                        if ($cacheValue % 20 == 0) {
                                            sendTGMessageToGroup('用户' . ($user['nickName']??$user['userName']) . '正在高强度使用不在建议客户端列表中的客户端: ' . $session['Client']);
                                        }
                                    }

                                }
                            }

                            $embyDevideModel = new EmbyDeviceModel();

                            $embyDevide = $embyDevideModel
                                ->where('embyId', $data['User']['Id'])
                                ->where('deviceId', $session['DeviceId'])
                                ->find();

                            if ($embyDevide) {
                                $embyDevideModel->where('id', $embyDevide['id'])->update([
                                    'lastUsedTime' => date('Y-m-d H:i:s'),
                                    'lastUsedIp' => $session['RemoteEndPoint'],
                                    'client' => $session['Client'],
                                    'deviceName' => $session['DeviceName']
                                ]);
                            } else {
                                $embyDevideModel->save([
                                    'embyId' => $data['User']['Id'],
                                    'deviceId' => $session['DeviceId'],
                                    'client' => $session['Client'],
                                    'deviceName' => $session['DeviceName'],
                                    'lastUsedTime' => date('Y-m-d H:i:s'),
                                    'lastUsedIp' => $session['RemoteEndPoint'],
                                    'deviceInfo' => json_encode([
                                        'sessionId' => $session['Id'],
                                    ]),
                                ]);
                            }

                            $embyDevideCount = $embyDevideModel
                                ->where('embyId', $data['User']['Id'])
                                ->where('lastUsedTime', '>', date('Y-m-d H:i:s', strtotime('-7 day')))
                                ->count();

                            if($embyDevideCount > 9 && $embyDevideCount <= 11) {
                                $cacheKey = 'embyDeviceCount_' . $data['User']['Id'];
                                $cacheValue = Cache::get($cacheKey);
                                if (!$cacheValue) {
                                    Cache::set($cacheKey, 1, 60*60*24);
                                    sendTGMessageToGroup('用户' . ($user['nickName']??$user['userName']) . '一周内使用设备数量超过10个，请检查是否有异常设备 (1天之内不再重复通知)');
                                    sendTGMessage($user['id'], '您的一周内使用设备数量超过10个，请检查是否有异常设备 (1天之内不再重复通知)');
                                }
                            } else if ($embyDevideCount >= 12) {
                                sendTGMessageToGroup('用户' . ($user['nickName']??$user['userName']) . '一周内使用设备数量达到(超过)12个，正在封禁用户');
                                $embyUserId = $data['User']['Id'];
                                $url = Config::get('media.urlBase') . 'Users/' . $embyUserId . '/Policy?api_key=' . Config::get('media.apiKey');
                                $data = [
                                    'IsDisabled' => true
                                ];
                                $ch = curl_init($url);
                                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                    'accept: */*',
                                    'Content-Type: application/json'
                                ]);
                                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                                $response = curl_exec($ch);
                                if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200 || curl_getinfo($ch, CURLINFO_HTTP_CODE) == 204) {
                                    $mediaMaturityTemplate = '您的' . Config::get('app.app_name') . '账号已经禁止使用。';
                                    sendTGMessage($user['id'], '您的' . Config::get('app.app_name') . '账号已经禁止使用。');
                                    sendEmail($user['email'], '账号已经禁止使用 - ' . Config::get('app.app_name'), $mediaMaturityTemplate);
                                }
                                $user->rCoin = 0;
                                $user->authority = -1;
                                $user->save();
                            }
                        }
                    }


                    $item = null;
                    if (isset($data['Item']) && $data['Item'] != '') {
                        $item = $data['Item'];
                    }
                    $session = null;
                    if (isset($data['Session']) && $data['Session'] != '') {
                        $session = $data['Session'];
                    }
                    $playbackInfo = null;
                    if (isset($data['PlaybackInfo']) && $data['PlaybackInfo'] != '') {
                        $playbackInfo = $data['PlaybackInfo'];
                    }

                    if ($data['Event']){
                        $type = 0;
                        if ($data['Event'] == 'system.notificationtest') {
                            // 测试通知
                        } elseif ($data['Event'] == 'playback.start') {
                            // 开始播放
                            $type = 1;
                        } elseif ($data['Event'] == 'playback.pause') {
                            // 暂停播放
                            $type = 2;
                        } elseif ($data['Event'] == 'playback.unpause') {
                            // 取消暂停
                            $type = 1;
                        } elseif ($data['Event'] == 'playback.stop') {
                            // 停止播放
                            $type = 3;
                        }

                        // 播放记录
                        if ($user && $item && $playbackInfo && $session && $type > 0) {
                            $mediaHistoryModel = new MediaHistoryModel();
                            $mediaHistory = $mediaHistoryModel->where([
                                'userId' => $user['id'],
                                'mediaId' => $item['Id'],
                            ])->find();
                            if ($mediaHistory) {
                                // 更新type为1
                                $mediaHistory->type = $type;
                                $mediaHistory->historyInfo = json_encode([
                                    'session' => $session,
                                    'item' => $item,
                                    'percentage' => (isset($data['PlaybackInfo']['PositionTicks']) && isset($data['Item']['RunTimeTicks']))?($data['PlaybackInfo']['PositionTicks'] / $data['Item']['RunTimeTicks']):0,
                                ]);
                                $mediaHistory->save();
                            } else {
                                $mediaHistoryModel->save([
                                    'type' => $type,
                                    'userId' => $user['id'],
                                    'mediaId' => $item['Id'],
                                    'mediaName' => $item['Name'],
                                    'mediaYear' => isset($item['PremiereDate'])?date('Y', strtotime($item['PremiereDate'])):null,
                                    'historyInfo' => json_encode([
                                        'session' => $session,
                                        'item' => $item,
                                        'percentage' => (isset($data['PlaybackInfo']['PositionTicks']) && isset($data['Item']['RunTimeTicks']))?($data['PlaybackInfo']['PositionTicks'] / $data['Item']['RunTimeTicks']):0,
                                    ])
                                ]);
                            }
                        }

                        // 播放完成通知
                        if ($user && $item && $playbackInfo && $type == 3 && (isset($data['PlaybackInfo']['PositionTicks']) && isset($data['Item']['RunTimeTicks'])) && $data['PlaybackInfo']['PositionTicks'] / $data['Item']['RunTimeTicks'] > 0.8) {
                            // 播放完成
                            if (isset($item['Type']) && $item['Type'] != '') {
                                $msg = '';
                                $keyList = Config::get('apiinfo.xfyunList');

                                if (empty($keyList) || !isset($item['Overview']) || $item['Overview'] == '') {
                                    if ($item['Type'] == 'Movie') {
                                        $msg = "感谢观看电影《" . $item['Name'] . "》，快来写一写影评吧。";
                                    } else if ($item['Type'] == 'Episode') {
                                        $msg = "感谢观看剧集《" . $item['SeriesName'] . "》中名为《" . $item['Name'] . "》的一集，快来写一写影评吧。";
                                    }
                                } else {
                                    if ($item['Type'] == 'Movie') {
                                        $inComeMessage = "用户刚刚看完了电影《" . $item['Name'] . "》，这部电影的简介是：" . $item['Overview'] . "，请你根据这部电影的特点，还有你的知识库，对用户表示感谢观看这部电影，并且期望用户在我的网站多看电影，回答内容中要包含电影名，直接告诉我需要告诉用户的内容。";
                                        $msg = getReplyFromAI('chat', $inComeMessage);
                                    } else if ($item['Type'] == 'Episode') {
                                        $inComeMessage = "用户刚刚看完了剧集《" . $item['SeriesName'] . "》中名为《" . $item['Name'] . "》的一集，这部剧集的简介是：" . $item['Overview'] . "，请你根据这部剧集的特点，还有你的知识库，对用户表示感谢观看这部剧集，并且期望用户在我的网站多看剧集，回答内容中要包含剧集名称和这一集的名称，直接告诉我需要告诉用户的内容。";
                                        $msg = getReplyFromAI('chat', $inComeMessage);
                                    }
                                }
                                if ($msg != '') {
                                    sendStationMessage($user['id'], $msg);
                                    $telegramToken = Config::get('telegram.botConfig.bots.randallanjie_bot.token');
                                    if ($telegramToken != 'notgbot') {
                                        $telegramModel = new TelegramModel();
                                        $telegramUser = $telegramModel->where('userId', $user['id'])->find();
                                        if ($telegramUser) {
                                            $telegramUserInfoArray = json_decode(json_encode($telegramUser['userInfo']), true);
                                            if (isset($telegramUserInfoArray['notification']) && $telegramUserInfoArray['notification'] == 1) {
                                                $telegram = new Api($telegramToken);
                                                $telegram->sendMessage([
                                                    'chat_id' => $telegramUser['telegramId'],
                                                    'text' => $msg,
                                                    'parse_mode' => 'HTML',
                                                ]);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }

                }
            }
        } catch (\Exception $exception) {
            $message = '第' . $exception->getLine() . '行发生错误：' . $exception->getMessage();
            // 错误内容
            $telegram = new Api(Config::get('telegram.botConfig.bots.randallanjie_bot.token'));
            $telegram->sendMessage([
                'chat_id' => Config::get('telegram.adminId'),
                'text' => $message . PHP_EOL . 'get: ' . json_encode(Request::get()) . PHP_EOL . 'post: ' . json_encode(Request::post()),
                'parse_mode' => 'HTML',
            ]);
            return false;
        }
    }
}
