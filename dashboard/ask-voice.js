const button = document.getElementById('sir-voice');
const status = document.getElementById('sir-voice-status');
const input = document.getElementById('sir-message');
const csrf = input.form.elements.namedItem('_csrf');

let session = null;

function stop(message) {
  const active = session;
  session = null;
  if (active) {
    clearTimeout(active.deadline);
    active.upload.abort();
    active.peer.close();
    active.stream?.getTracks().forEach((track) => track.stop());
  }
  button.disabled = false;
  button.textContent = 'Start voice input';
  status.textContent = message;
}

function finish(active) {
  if (active.channel.readyState !== 'open') {
    stop('Voice did not connect. You can type your message.');
    return;
  }

  active.stream.getTracks().forEach((track) => { track.enabled = false; });
  button.disabled = true;
  status.textContent = 'Transcribing…';
  // The data channel and audio track are independent, so let audio in flight arrive.
  setTimeout(() => {
    if (session !== active) return;
    if (active.channel.readyState !== 'open') {
      stop('Voice disconnected. You can type your message.');
      return;
    }
    active.channel.send(JSON.stringify({ type: 'input_audio_buffer.commit' }));
    active.stream.getTracks().forEach((track) => track.stop());
  }, 250);
}

async function start() {
  if (!navigator.mediaDevices?.getUserMedia || typeof RTCPeerConnection !== 'function') {
    stop('Microphone input is unavailable in this browser. You can type your message.');
    return;
  }

  const peer = new RTCPeerConnection();
  const channel = peer.createDataChannel('oai-events');
  const active = { peer, channel, upload: new AbortController() };
  session = active;
  button.disabled = true;
  status.textContent = 'Connecting microphone…';
  active.deadline = setTimeout(() => stop('Voice session ended. You can type your message.'), 45_000);

  try {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    if (session !== active) {
      stream.getTracks().forEach((track) => track.stop());
      return;
    }
    active.stream = stream;
    const [track] = stream.getAudioTracks();
    track.enabled = false;
    peer.addTrack(track, stream);
    channel.addEventListener('open', () => {
      if (session !== active) return;
      track.enabled = true;
      button.textContent = 'Finish voice input';
      button.disabled = false;
      status.textContent = 'Speak a short message, then choose Finish.';
    });
    channel.addEventListener('message', ({ data }) => {
      if (session !== active) return;
      const event = JSON.parse(data);
      if (event.type === 'conversation.item.input_audio_transcription.completed'
          && typeof event.transcript === 'string') {
        input.value = event.transcript.trim().replace(/[.!?]+$/, '').slice(0, input.maxLength);
        stop('Review or edit the transcript, then choose Ask Sir.');
        input.focus();
      } else if (event.type === 'error' || event.type === 'conversation.item.input_audio_transcription.failed') {
        stop('Voice transcription failed. You can type your message.');
      }
    });
    peer.addEventListener('connectionstatechange', () => {
      if (session === active && ['failed', 'closed'].includes(peer.connectionState)) {
        stop('Voice disconnected. You can type your message.');
      }
    });

    const offer = await peer.createOffer();
    await peer.setLocalDescription(offer);
    const response = await fetch('ask-voice', {
      method: 'POST',
      body: new URLSearchParams({ _csrf: csrf.value, sdp: offer.sdp }),
      signal: active.upload.signal,
    });
    if (!response.ok) throw new Error('Voice session unavailable');
    await peer.setRemoteDescription({ type: 'answer', sdp: await response.text() });
  } catch {
    if (session === active) stop('Voice could not start. Check microphone access and the API key, or type your message.');
  }
}

button.addEventListener('click', () => {
  if (session) finish(session);
  else start();
});
window.addEventListener('pagehide', () => stop('Voice disconnected.'));
