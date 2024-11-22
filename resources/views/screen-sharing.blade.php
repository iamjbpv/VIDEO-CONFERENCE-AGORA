<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agora Video Conference with Device Selection</title>
    <script src="https://cdn.agora.io/sdk/release/AgoraRTC_N-4.13.0.js"></script>
    <script src="https://unpkg.com/agora-rtm-sdk@1.5.1/index.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"
        integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>

    <style>
        #video-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .video-box {
            width: 300px;
            height: 200px;
            background: #000;
        }

        button,
        select {
            margin-right: 10px;
            margin-top: 10px;
        }

        #audio-meter {
            margin-top: 10px;
            height: 20px;
            background: #ddd;
            position: relative;
        }

        #audio-level {
            height: 100%;
            background: green;
            width: 0;
        }
    </style>
</head>

<body>
    <h1>Agora Video Conference with Device Selection</h1>
    <button id="joinConference">Join Conference</button>
    <button id="startScreenShare">Start Screen Sharing</button>
    <button id="toggleCamera" disabled>Turn Off Camera</button>
    <button id="toggleMic" disabled>Turn Off Microphone</button>

    <label for="micSelection">Microphone:</label>
    <select id="micSelection"></select>

    <label for="speakerSelection">Speaker:</label>
    <select id="speakerSelection"></select>

    <div id="video-grid"></div>
    <div id="video-screen-share">

    </div>

    <h3>Microphone Level</h3>
    <div id="audio-meter">
        <div id="audio-level"></div>
    </div>

    <script>
        $(document).ready(function () {
            const appId = '{{ env('AGORA_APP_ID') }}';
            const channelName = 'test_channel';
            const uid = Math.floor(Math.random() * 10000);

            // Agora RTC and RTM clients
            const client = AgoraRTC.createClient({ mode: "rtc", codec: "vp8" });
            const remoteUsers = {};
            let rtmClient;
            let rtmChannel;
            let localTracks = {
                videoTrack: null,
                audioTrack: null,
            };
            let screenTrack = null;

            const loadAudioDevices = async () => {
                const devices = await AgoraRTC.getDevices();
                const micSelection = document.getElementById('micSelection');
                const speakerSelection = document.getElementById('speakerSelection');

                devices.forEach((device) => {
                    const option = document.createElement('option');
                    option.value = device.deviceId;
                    option.text = device.label || `${device.kind === "audioinput" ? "Microphone" : "Speaker"}`;
                    if (device.kind === "audioinput") {
                        micSelection.appendChild(option);
                    } else if (device.kind === "audiooutput") {
                        speakerSelection.appendChild(option);
                    }
                });
            };

            const initializeRTM = async () => {
                rtmClient = AgoraRTM.createInstance(appId);
                await rtmClient.login({ uid: String(uid) });
                rtmChannel = rtmClient.createChannel(channelName);

                rtmChannel.on('ChannelMessage', (message, memberId) => {
                    const data = JSON.parse(message.text);
                    if (data.type === 'screen-sharing') {
                        handleScreenSharingNotification(data.uid, data.isScreenSharing);
                    }
                });

                await rtmChannel.join();
                console.log('RTM channel joined');
            };

            const joinConference = async (token) => {
                await client.join(appId, channelName, token, uid);

                const selectedMic = document.getElementById('micSelection').value;
                localTracks.audioTrack = await AgoraRTC.createMicrophoneAudioTrack({ microphoneId: selectedMic });
                await client.publish(localTracks.audioTrack);


                // Publish video (camera) track
                try {
                    localTracks.videoTrack = await AgoraRTC.createCameraVideoTrack();
                    const cameraPlayer = document.createElement('div');
                    cameraPlayer.id = `player-${uid}-camera`;
                    cameraPlayer.classList.add('video-box');
                    document.getElementById('video-grid').appendChild(cameraPlayer);
                    localTracks.videoTrack.play(cameraPlayer);

                    await client.publish(localTracks.videoTrack);
                } catch (err) {
                    console.log('video err', err)
                }


                document.getElementById('toggleCamera').disabled = false;
                document.getElementById('toggleMic').disabled = false;

                await initializeRTM();
            };
            const startScreenSharing = async () => {
                screenTrack = await AgoraRTC.createScreenVideoTrack();
                await client.publish(screenTrack);

                const screenPlayer = document.createElement('div');
                screenPlayer.id = `player-${uid}-screen`;
                screenPlayer.classList.add('video-box');
                document.getElementById('video-screen-share').appendChild(screenPlayer);
                screenTrack.play(screenPlayer);

                notifyScreenSharing(true);

                screenTrack.on('track-ended', async () => {
                    stopScreenSharing();
                });
            };

            const stopScreenSharing = async () => {
                await client.unpublish(screenTrack);
                screenTrack.stop();
                screenTrack.close();
                document.getElementById(`player-${uid}-screen`).remove();
                screenTrack = null;

                notifyScreenSharing(false);
            };

            const notifyScreenSharing = async (isScreenSharing) => {
                const message = JSON.stringify({ type: 'screen-sharing', uid, isScreenSharing });
                await rtmChannel.sendMessage({ text: message });
            };

            const handleScreenSharingNotification = (uid, isScreenSharing) => {
                console.log(`Screen sharing notification from UID ${uid}: ${isScreenSharing}`);
                if (!remoteUsers[uid]) {
                    remoteUsers[uid] = { cameraTrack: null, screenTrack: null };
                }
                remoteUsers[uid].isScreenSharing = isScreenSharing;
                if (!isScreenSharing) {
                    document.getElementById(`player-${uid}-screen`).remove();
                }
            };

            const handleUserPublished = async (user, mediaType) => {
                await client.subscribe(user, mediaType);
                let playerId = `player-${user.uid}-${mediaType === 'video' ? 'camera' : 'audio'}`;
                if (mediaType === 'video') {
                    const delay = ms => new Promise(res => setTimeout(res, ms));
                    await delay(3000);
                    let isUserIdSharing = remoteUsers[user.uid]?.isScreenSharing;
                    console.log('SHARING', remoteUsers[user.uid]?.isScreenSharing);
                    playerId = remoteUsers[user.uid]?.isScreenSharing ? `player-${user.uid}-screen` : `player-${user.uid}-camera`;
                    const player = document.createElement('div');
                    player.id = playerId;
                    player.classList.add('video-box');
                    if (isUserIdSharing) {
                        document.getElementById('video-screen-share').appendChild(player);
                    } else {
                        document.getElementById('video-grid').appendChild(player);
                    }
                    user.videoTrack.play(player);
                } else if (mediaType === 'audio') {
                    user.audioTrack.play();
                }
            };

            client.on('user-published', handleUserPublished);

            document.getElementById('joinConference').addEventListener('click', async () => {
                const response = await fetch('/api/generate-token', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ channel_name: channelName, uid }),
                });
                const { token } = await response.json();
                await joinConference(token);
            });

            // document.getElementById('startScreenShare').addEventListener('click', startScreenSharing);
            $("#startScreenShare").click(function () {
                if (!screenTrack) {
                    $(this).html('Stop Screen Sharing');
                    startScreenSharing();
                } else {
                    $(this).html('Start Screen Sharing');
                    stopScreenSharing();
                }
            });

            loadAudioDevices();
        });

    </script>
</body>

</html>