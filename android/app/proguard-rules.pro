-keepattributes *Annotation*, InnerClasses
-keep,includedescriptorclasses class ametecs.managedmobile.**$$serializer { *; }
-keepclassmembers class ametecs.managedmobile.** { *** Companion; }
-keep class org.webrtc.** { *; }
-keepclassmembers class * { @android.webkit.JavascriptInterface <methods>; }   # field map WebView bridge
# WebRTC's native code calls these classes BY NAME (org/jni_zero/JniUtil builds the video codec lists). R8 removed them,
# so every camera / screen session aborted natively at start (SIGTRAP in jni_zero.cc) in release builds only.
-keep class org.jni_zero.** { *; }
-keepclasseswithmembers,includedescriptorclasses class * { @org.jni_zero.CalledByNative *; }
-keepclasseswithmembers,includedescriptorclasses class * { @org.jni_zero.CalledByNativeUnchecked *; }
-keepclasseswithmembers,includedescriptorclasses class * { @org.jni_zero.AccessedByNative *; }
