<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agora Video Conference with Device Selection</title>
    <script src="https://cdn.agora.io/sdk/release/AgoraRTC_N-4.13.0.js"></script>
    <script src="https://cdn.agora.io/sdk/release/AgoraRTM_N-1.5.1.js"></script>
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

    <h3>Microphone Level</h3>
    <div id="audio-meter">
        <div id="audio-level"></div>
    </div>

    <script>
        const appId = '{{ env('AGORA_APP_ID') }}';
        const channelName = 'test_channel';
        const uid = Math.floor(Math.random() * 10000);

        // Agora RTC client
        const client = AgoraRTC.createClient({ mode: "rtc", codec: "vp8" });
        let localTracks = {
            videoTrack: null,
            audioTrack: null,
        };
        let screenTrack = null;
        const remoteUsers = {};
        let cameraEnabled = true;
        let micEnabled = true;

        const loadAudioDevices = async () => {
            const devices = await AgoraRTC.getDevices();

            const micDevices = devices.filter(device => device.kind === "audioinput");
            const speakerDevices = devices.filter(device => device.kind === "audiooutput");

            const micSelection = document.getElementById('micSelection');
            const speakerSelection = document.getElementById('speakerSelection');

            // microphone selection
            micDevices.forEach(device => {
                const option = document.createElement('option');
                option.value = device.deviceId;
                option.text = device.label || `Microphone ${micSelection.length + 1}`;
                micSelection.appendChild(option);
            });

            // Speaker Selection
            speakerDevices.forEach(device => {
                const option = document.createElement('option');
                option.value = device.deviceId;
                option.text = device.label || `Speaker ${speakerSelection.length + 1}`;
                speakerSelection.appendChild(option);
            });
        };

        const joinConference = async (token) => {
            //   Agora channel
            await client.join(appId, channelName, token, uid);

            // Create and publish local audio/video tracks
            const selectedMic = document.getElementById('micSelection').value;
            localTracks.audioTrack = await AgoraRTC.createMicrophoneAudioTrack({ microphoneId: selectedMic });
            if (localTracks.audioTrack != null) {
                await client.publish(localTracks.audioTrack);
                console.log('Published audio tracks');
            }

            localTracks.videoTrack = await AgoraRTC.createCameraVideoTrack();
            if (localTracks.videoTrack != null) {
                const localPlayer = document.createElement('div');
                localPlayer.id = `player-${uid}`;
                localPlayer.classList.add('video-box');
                document.getElementById('video-grid').appendChild(localPlayer);
                localTracks.videoTrack.play(localPlayer);

                await client.publish(localTracks.videoTrack);
                console.log('Published video tracks');
            }

            document.getElementById('toggleCamera').disabled = false;
            document.getElementById('toggleMic').disabled = false;


            monitorAudioLevel(localTracks.audioTrack);
            await initializeRTM();
        };

        const initializeRTM = async () => {
            rtmClient = AgoraRTM.createInstance(appId);
            await rtmClient.login({ uid: String(uid) });
            rtmChannel = rtmClient.createChannel(channelName);

            rtmChannel.on('ChannelMessage', (message, memberId) => {
                const data = JSON.parse(message.text);
                console.log('CHANNEL DATA', data);
                if (data.type === 'screen-sharing') {
                    handleScreenSharingNotification(data.uid, data.isScreenSharing);
                }
            });

            await rtmChannel.join();
            console.log('RTM channel joined');
        };

        const startScreenSharing = async () => {
            if (screenTrack) return;
            screenTrack = await AgoraRTC.createScreenVideoTrack();
            await client.publish(screenTrack);

            const screenPlayer = document.createElement('div');
            screenPlayer.id = `player-${uid}-screen`;
            screenPlayer.classList.add('video-box');
            document.getElementById('video-grid').appendChild(screenPlayer);
            screenTrack.play(screenPlayer);

            notifyScreenSharing(true);

            screenTrack.on('track-ended', async () => {
                stopScreenSharing();
            });
        };
        const stopScreenSharing = async () => {
            if (!screenTrack) return;
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

        const monitorAudioLevel = (audioTrack) => {
            setInterval(() => {
                const volume = audioTrack.getVolumeLevel();
                const audioLevel = document.getElementById('audio-level');
                audioLevel.style.width = `${volume * 100}%`;
            }, 100);
        };

        const toggleCamera = async () => {
            if (cameraEnabled) {
                await client.unpublish(localTracks.videoTrack);
                localTracks.videoTrack.stop();
                console.log('Camera turned off');
                document.getElementById('toggleCamera').innerText = 'Turn On Camera';
            } else {
                localTracks.videoTrack = await AgoraRTC.createCameraVideoTrack();
                await client.publish(localTracks.videoTrack);
                localTracks.videoTrack.play(`player-${uid}`);
                console.log('Camera turned on');
                document.getElementById('toggleCamera').innerText = 'Turn Off Camera';
            }
            cameraEnabled = !cameraEnabled;
        };

        const toggleMic = async () => {
            if (micEnabled) {
                await client.unpublish(localTracks.audioTrack);
                localTracks.audioTrack.stop();
                console.log('Microphone turned off');
                document.getElementById('toggleMic').innerText = 'Turn On Microphone';
            } else {
                const selectedMic = document.getElementById('micSelection').value;
                localTracks.audioTrack = await AgoraRTC.createMicrophoneAudioTrack({ microphoneId: selectedMic });
                await client.publish(localTracks.audioTrack);
                console.log('Microphone turned on');
                document.getElementById('toggleMic').innerText = 'Turn Off Microphone';
            }
            micEnabled = !micEnabled;
        };

        const handleUserPublished = async (user, mediaType) => {
            await client.subscribe(user, mediaType);
            console.log(`Subscribed to user: ${user.uid}`);

            if (mediaType === 'video') {
                const remotePlayer = document.createElement('div');
                remotePlayer.id = `player-${user.uid}`;
                remotePlayer.classList.add('video-box');
                document.getElementById('video-grid').appendChild(remotePlayer);
                user.videoTrack.play(remotePlayer);
            }

            if (mediaType === 'audio') {
                user.audioTrack.play(); // Play other user's audio
                console.log(`Playing audio from user: ${user.uid}`);
            }
        };

        client.on('user-published', handleUserPublished);

        document.getElementById('joinConference').addEventListener('click', async () => {
            const response = await fetch('/api/api/generate-token', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ channel_name: channelName, uid }),
            });
            const { token } = await response.json();
            await joinConference(token);
        });

        document.getElementById('startScreenShare').addEventListener('click', async () => {
            try {
                await startScreenSharing();
            } catch (error) {
                console.error('Error starting screen sharing:', error);
            }
        });


        document.getElementById('micSelection').addEventListener('change', async () => {
            const selectedMic = document.getElementById('micSelection').value;
            await localTracks.audioTrack.setDevice(selectedMic);
            console.log(`Switched microphone to ${selectedMic}`);
        });

        document.getElementById('speakerSelection').addEventListener('change', () => {
            const selectedSpeaker = document.getElementById('speakerSelection').value;
            AgoraRTC.setPlaybackDevice(selectedSpeaker);
            console.log(`Switched speaker to ${selectedSpeaker}`);
        });

        loadAudioDevices();
    </script>
</body>

</html>