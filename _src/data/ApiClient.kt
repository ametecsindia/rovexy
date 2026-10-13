package ametecs.managedmobile.data

import ametecs.managedmobile.core.RequestSigning
import ametecs.managedmobile.security.DeviceKeys
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.util.concurrent.TimeUnit

class ApiException(val status: Int, val code: String, message: String) : Exception(message)

/** Low-level HTTPS client. Credentials are sent only in headers — never in URLs. TLS uses system trust only. */
class ApiClient(private val config: ServerConfig, private val keys: DeviceKeys) {
    val json = Json { ignoreUnknownKeys = true }
    private val http = OkHttpClient.Builder()
        .connectTimeout(10, TimeUnit.SECONDS).readTimeout(20, TimeUnit.SECONDS).callTimeout(30, TimeUnit.SECONDS)
        .build()
    @Volatile var lastLeaseHeader: String? = null

    enum class Auth { NONE, SIGNED, DEVICE, WORK }

    suspend fun call(method: String, path: String, body: JsonObject? = null, auth: Auth, token: String? = null): JsonElement =
        withContext(Dispatchers.IO) {
            val origin = config.serverOrigin ?: throw ApiException(0, "no_server", "Server not configured")
            val bytes = (body?.toString() ?: "").toByteArray()
            val reqBody: RequestBody? = if (method == "GET") null else bytes.toRequestBody(JSON)
            execute(newRequest(origin, path, auth, token).method(method, reqBody).also { r ->
                if (auth == Auth.SIGNED) sign(r, method, path, bytes)
            }.build())
        }

    suspend fun multipart(path: String, parts: MultipartBody, token: String): JsonElement = withContext(Dispatchers.IO) {
        val origin = config.serverOrigin ?: throw ApiException(0, "no_server", "Server not configured")
        execute(newRequest(origin, path, Auth.WORK, token).post(parts).build(), uploadHttp)
    }

    // Call recordings can be several MB on a slow mobile link.
    private val uploadHttp by lazy { http.newBuilder().writeTimeout(120, TimeUnit.SECONDS).callTimeout(180, TimeUnit.SECONDS).build() }

    private fun newRequest(origin: String, path: String, auth: Auth, token: String?): Request.Builder {
        val b = Request.Builder().url(origin + path).header("Accept", "application/json")
        config.workspace?.let { b.header("X-Workspace", it) }
        if ((auth == Auth.DEVICE || auth == Auth.WORK) && token != null) b.header("Authorization", "Bearer $token")
        return b
    }

    private fun sign(b: Request.Builder, method: String, path: String, body: ByteArray) {
        val ts = System.currentTimeMillis() / 1000
        val nonce = RequestSigning.nonce()
        b.header("X-Installation-Id", keys.installationId)
            .header("X-Timestamp", ts.toString())
            .header("X-Nonce", nonce)
            .header("X-Signature", keys.sign(RequestSigning.canonical(method, path, ts, nonce, body).toByteArray()))
    }

    private fun execute(req: Request, client: OkHttpClient = http): JsonElement {
        client.newCall(req).execute().use { resp ->
            resp.header("X-Offline-Lease-Expires")?.let { lastLeaseHeader = it }
            val text = resp.body?.string().orEmpty()
            val el = runCatching { json.parseToJsonElement(text.ifEmpty { "{}" }) }.getOrElse { JsonObject(emptyMap()) }
            if (!resp.isSuccessful) {
                val obj = (el as? JsonObject)
                val code = obj?.get("error")?.jsonPrimitive?.content ?: "http_${resp.code}"
                val msg = obj?.get("message")?.jsonPrimitive?.content ?: code.replace('_', ' ')
                throw ApiException(resp.code, code, msg)
            }
            return el
        }
    }

    companion object { val JSON = "application/json".toMediaType() }
}

fun JsonElement.obj(): JsonObject = this.jsonObject
fun JsonObject.str(k: String): String? = this[k]?.let { runCatching { it.jsonPrimitive.content }.getOrNull() }?.takeIf { it != "null" }
