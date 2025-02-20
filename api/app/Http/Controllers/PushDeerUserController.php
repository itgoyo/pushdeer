<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PushDeerUser;
use App\Models\PushDeerKey;
use App\Models\PushDeerDevice;
use App\Models\PushDeerMessage;

class PushDeerUserController extends Controller
{
    public function info(Request $request)
    {
        //return $_SESSION;
        return http_result(PushDeerUser::where('id', $_SESSION['uid'])->get()->first());
    }

    public function fakeLogin(Request $request)
    {
        if (!env('APP_DEBUG')) {
            return send_error('Debug only', ErrorCode('ARGS'));
        }

        $info = [
            'uid' => 'theid999',
            'email' => 'easychen+new@gmail.com',
        ];

        if (isset($info['uid'])) {
            // 通过 uid 查询 user 表，如果存在自动登入，如果不存在自动注册并登入
            $pd_user = PushDeerUser::where('apple_id', $info['uid'])->get()->first();
            if (!$pd_user) {
                // 用户不存在，创建用户
                $the_user = [];
                $the_user['apple_id'] = $info['uid'];
                $the_user['email'] = $info['email'];
                $the_user['name'] = @reset(explode('@', $info['email']));
                $the_user['level'] = 1;

                $pd_user = PushDeerUser::create($the_user);
            }

            // 将数据写到session
            session_start();
            $_SESSION['uid'] = $pd_user['id'];
            $_SESSION['name'] = $pd_user['name'];
            $_SESSION['email'] = $pd_user['email'];
            $_SESSION['level'] = $pd_user['level'];

            session_regenerate_id(true);
            $token = session_id();
            return http_result(['token'=>$token]);
        }

        return send_error('id_token解析错误', ErrorCode('ARGS'));
    }

    public function wechatLogin(Request $request)
    {
        $validated = $request->validate(
            [
                'code' => 'string',
            ]
        );

        if (isset($validated['code'])) {
            // 解码并进行验证
            $url = "https://api.weixin.qq.com/sns/oauth2/access_token?appid="
            .urlencode(env("WECHAT_APPID"))
            ."&secret="
            .urlencode(env("WECHAT_APPSECRET"))
            ."&code="
            .urlencode($validated['code'])
            ."&grant_type=authorization_code";

            $code_info = json_decode(file_get_contents($url), true);

            if (!$code_info || !isset($code_info['access_token']) || !isset($code_info['unionid'])) {
                return send_error("错误的Code", ErrorCode('REMOTE'));
            }

            // 现在拿到unionid了

            $pd_user = PushDeerUser::where('wechat_id', $code_info['unionid'])->get()->first();
            if (!$pd_user) {
                // 用户不存在，创建用户
                $the_user = [];
                $the_user['wechat_id'] = $code_info['unionid'];
                $the_user['email'] = $code_info['unionid'].'@'.'fake.pushdeer.com';
                $the_user['name'] = '微信用户'.substr($code_info['unionid'], 0, 6);
                $the_user['level'] = 1;

                $pd_user = PushDeerUser::create($the_user);
            }

            // 将数据写到session
            session_start();
            $_SESSION['uid'] = $pd_user['id'];
            $_SESSION['name'] = $pd_user['name'];
            $_SESSION['email'] = $pd_user['email'];
            $_SESSION['level'] = $pd_user['level'];

            session_regenerate_id(true);
            $token = session_id();
            return http_result(['token'=>$token]);
        }


        return send_error('微信Code错误', ErrorCode('ARGS'));
    }

    //
    public function login(Request $request)
    {
        $validated = $request->validate(
            [
                'idToken' => 'string',
            ]
        );

        if (isset($validated['idToken'])) {
            // 解码并进行验证
            $info = getUserDataFromIdentityToken($validated['idToken']);
            if (isset($info['uid'])) {
                // 通过 uid 查询 user 表，如果存在自动登入，如果不存在自动注册并登入
                $pd_user = PushDeerUser::where('apple_id', $info['uid'])->get()->first();
                if (!$pd_user) {
                    // 用户不存在，创建用户
                    $the_user = [];
                    $the_user['apple_id'] = $info['uid'];
                    $the_user['email'] = $info['email'];
                    $the_user['name'] = @reset(explode('@', $info['email']));
                    $the_user['level'] = 1;

                    $pd_user = PushDeerUser::create($the_user);
                }

                // 将数据写到session
                session_start();
                $_SESSION['uid'] = $pd_user['id'];
                $_SESSION['name'] = $pd_user['name'];
                $_SESSION['email'] = $pd_user['email'];
                $_SESSION['level'] = $pd_user['level'];

                session_regenerate_id(true);
                $token = session_id();
                return http_result(['token'=>$token]);
            }
        }


        return send_error('id_token解析错误', ErrorCode('ARGS'));
    }

    public function merge(Request $request)
    {
        $validated = $request->validate(
            [
                'tokenorcode' => 'string|required',
                'type' => 'string|required', // apple or wechat
            ]
        );



        $type_field = strtolower($validated['type']) == 'apple' ? 'apple_id' : 'wechat_id';



        $identiy_string = false;
        if ($type_field == 'apple_id') {
            $info = getUserDataFromIdentityToken($validated['tokenorcode']);
            if ($info && isset($info['uid'])) {
                $identiy_string = $info['uid'];
            }
        } else {
            // wechat
            // 解码并进行验证
            $url = "https://api.weixin.qq.com/sns/oauth2/access_token?appid="
            .urlencode(env("WECHAT_APPID"))
            ."&secret="
            .urlencode(env("WECHAT_APPSECRET"))
            ."&code="
            .urlencode($validated['tokenorcode'])
            ."&grant_type=authorization_code";

            $info = json_decode(file_get_contents($url), true);

            if ($info && $info['unionid']) {
                $identiy_string = $info['unionid'];
            }
        }

        if (!$identiy_string) {
            return send_error('错误的token 或者 code', ErrorCode('ARGS'));
        }

        $user2delete = PushDeerUser::where($type_field, $identiy_string)->get()->first();
        $current_user = PushDeerUser::where('id', uid())->get()->first();



        // 如果存在旧用户，合并并删除
        if ($user2delete) {
            if ($user2delete['id'] == $current_user['id']) {
                return send_error("不能合并当前账号本身", ErrorCode('ARGS'));
            }


            // 删除Key
            PushDeerKey::where('uid', $user2delete['id'])->delete();

            // message合并
            PushDeerMessage::where('uid', $user2delete['id'])->update(['uid'=>uid()]);

            // 设备合并
            PushDeerDevice::where('uid', $user2delete['id'])->update(['uid'=>uid()]);

            // 删除用户
            $user2delete->delete();
        }

        $current_user[$type_field] = $identiy_string;
        $current_user->save();

        return http_result(['result'=>'done']);
    }
}
