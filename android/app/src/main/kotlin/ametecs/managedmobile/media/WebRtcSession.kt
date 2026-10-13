package ametecs.managedmobile.media

import android.content.Context
import android.content.Intent
import android.media.projection.MediaProjection
import org.webrtc.Camera2Enumerator
import org.webrtc.DefaultVideoDecoderFactory
import org.webrtc.DefaultVideoEncoderFactory
import org.webrtc.EglBase
import org.webrtc.IceCandidate
import org.webrtc.MediaConstraints
import org.webrtc.MediaStream
import org.webrtc.PeerConnection
import org.webrtc.PeerConnectionFactory
import org.webrtc.RtpReceiver
import org.webrtc.ScreenCapturerAndroid
import org.webrtc.SdpObserver
import org.webrtc.SessionDescription
import org.webrtc.SurfaceTextureHelper
import org.webrtc.VideoCapturer
import org.webrtc.VideoSink
import org.webrtc.VideoTrack

/**
 * Send-only WebRTC session (phone → supervisor console). The phone is the offerer; signalling goes over the server
 * (REST out, WebSocket in). Created only after the employee accepted AND the OS consent/permission was granted.
 */
class WebRtcSession(
    private val context: Context,
    val sessionId: String,
    val kind: String,                                // screen | camera | voice
    private val iceServers: List<PeerConnection.IceServer>,
    private val sendSignal: (type: String, payload: Map<String, Any?>) -> Unit,
    private val onEnded: (reason: String) -> Unit,
) {
    private val egl = EglBase.create()
    private lateinit var factory: PeerConnectionFactory
    private var pc: PeerConnection? = null
    private var capturer: VideoCapturer? = null
    private var helper: SurfaceTextureHelper? = null
    private var audioSource: org.webrtc.AudioSource? = null
    private var audioTrack: org.webrtc.AudioTrack? = null
    private val audio by lazy { context.getSystemService(Context.AUDIO_SERVICE) as android.media.AudioManager }
    var localTrack: VideoTrack? = null
        private set
    val eglContext: EglBase.Context get() = egl.eglBaseContext

    fun start(projectionData: Intent?, width: Int, height: Int, fps: Int) {
        PeerConnectionFactory.initialize(PeerConnectionFactory.InitializationOptions.builder(context).createInitializationOptions())
        factory = PeerConnectionFactory.builder()
            .setVideoEncoderFactory(DefaultVideoEncoderFactory(egl.eglBaseContext, true, true))
            .setVideoDecoderFactory(DefaultVideoDecoderFactory(egl.eglBaseContext))
            .createPeerConnectionFactory()

        if (kind != "voice") startVideo(projectionData, width, height, fps)
        else {
            // Two-way voice: the phone's mic goes out, the supervisor's voice is played by WebRTC's own audio module.
            audio.mode = android.media.AudioManager.MODE_IN_COMMUNICATION
            audio.isSpeakerphoneOn = true
            audioSource = factory.createAudioSource(MediaConstraints())
            audioTrack = factory.createAudioTrack("ammp-voice", audioSource)
        }
        connect()
    }

    private fun startVideo(projectionData: Intent?, width: Int, height: Int, fps: Int) {
        capturer = if (kind == "screen") {
            ScreenCapturerAndroid(projectionData!!, object : MediaProjection.Callback() {
                override fun onStop() = end("employee_stopped")   // user stopped via system UI / status bar chip
            })
        } else {
            val en = Camera2Enumerator(context)
            val name = en.deviceNames.firstOrNull { en.isFrontFacing(it) } ?: en.deviceNames.first()
            en.createCapturer(name, null)
        }
        helper = SurfaceTextureHelper.create("ammp-capture", egl.eglBaseContext)
        val source = factory.createVideoSource(kind == "screen")
        capturer!!.initialize(helper, context, source.capturerObserver)
        capturer!!.startCapture(width, height, fps)
        localTrack = factory.createVideoTrack("ammp-$kind", source)
    }

    private fun connect() {
        val cfg = PeerConnection.RTCConfiguration(iceServers).apply { sdpSemantics = PeerConnection.SdpSemantics.UNIFIED_PLAN }
        pc = factory.createPeerConnection(cfg, object : PeerConnection.Observer {
            override fun onIceCandidate(c: IceCandidate) = sendSignal("candidate", mapOf("candidate" to c.sdp, "sdpMid" to c.sdpMid, "sdpMLineIndex" to c.sdpMLineIndex))
            override fun onConnectionChange(state: PeerConnection.PeerConnectionState) {
                if (state == PeerConnection.PeerConnectionState.FAILED) end("connection_failed")
            }
            override fun onSignalingChange(p0: PeerConnection.SignalingState?) {}
            override fun onIceConnectionChange(p0: PeerConnection.IceConnectionState?) {}
            override fun onIceConnectionReceivingChange(p0: Boolean) {}
            override fun onIceGatheringChange(p0: PeerConnection.IceGatheringState?) {}
            override fun onIceCandidatesRemoved(p0: Array<out IceCandidate>?) {}
            override fun onAddStream(p0: MediaStream?) {}
            override fun onRemoveStream(p0: MediaStream?) {}
            override fun onDataChannel(p0: org.webrtc.DataChannel?) {}
            override fun onRenegotiationNeeded() {}
            override fun onAddTrack(p0: RtpReceiver?, p1: Array<out MediaStream>?) {}
        })
        localTrack?.let { pc!!.addTrack(it, listOf("ammp")) }
        audioTrack?.let { pc!!.addTrack(it, listOf("ammp")) }
        pc!!.createOffer(object : SimpleSdp() {
            override fun onCreateSuccess(sdp: SessionDescription) {
                pc!!.setLocalDescription(SimpleSdp(), sdp)
                sendSignal("offer", mapOf("type" to "offer", "sdp" to sdp.description))
            }
        }, MediaConstraints())
    }

    fun onRemoteSignal(type: String, payload: Map<String, Any?>) {
        when (type) {
            "answer" -> pc?.setRemoteDescription(SimpleSdp(), SessionDescription(SessionDescription.Type.ANSWER, payload["sdp"] as String))
            "candidate" -> pc?.addIceCandidate(IceCandidate(payload["sdpMid"] as String?, (payload["sdpMLineIndex"] as Number).toInt(), payload["candidate"] as String))
            "bye" -> end("supervisor_stopped")
        }
    }

    fun attachPreview(sink: VideoSink) { localTrack?.addSink(sink) }

    @Volatile private var ended = false
    fun end(reason: String) {
        if (ended) return
        ended = true
        runCatching { capturer?.stopCapture() }
        capturer?.dispose()
        pc?.close()
        audioSource?.dispose()
        if (kind == "voice") { audio.isSpeakerphoneOn = false; audio.mode = android.media.AudioManager.MODE_NORMAL }
        helper?.dispose()
        egl.release()
        onEnded(reason)
    }

    private open class SimpleSdp : SdpObserver {
        override fun onCreateSuccess(sdp: SessionDescription) {}
        override fun onSetSuccess() {}
        override fun onCreateFailure(e: String?) {}
        override fun onSetFailure(e: String?) {}
    }
}
