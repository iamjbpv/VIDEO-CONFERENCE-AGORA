<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Classes\AgoraDynamicKey\RtcTokenBuilder;

class AgoraController extends Controller
{
    public function generateToken(Request $request)
    {
        $appId = env('AGORA_APP_ID');
        $appCertificate = env('AGORA_APP_CERTIFICATE');
        $channelName = $request->input('channel_name');
        $uid = $request->input('uid') ?? 0; // Use 0 for non-UID users
        $role = RtcTokenBuilder::RoleAttendee;
        $expireTimeInSeconds = 3600; // Token expiration time
        $currentTimestamp = now()->timestamp;
        $privilegeExpireTime = $currentTimestamp + $expireTimeInSeconds;

        $token = RtcTokenBuilder::buildTokenWithUid(
            $appId,
            $appCertificate,
            $channelName,
            $uid,
            $role,
            $privilegeExpireTime
        );

        return response()->json(['token' => $token]);
    }
}
