package ametecs.managedmobile.work

import android.content.Context
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import ametecs.managedmobile.AmmpApp
import ametecs.managedmobile.data.ApiException
import java.util.concurrent.TimeUnit

/** Periodic + on-demand sync. This (not push) is the source of truth for policy/approval/customers. */
class SyncWorker(ctx: Context, params: WorkerParameters) : CoroutineWorker(ctx, params) {
    override suspend fun doWork(): Result {
        val g = (applicationContext as AmmpApp).graph
        return try {
            if (g.store.getString("registered") == null) return Result.success()   // nothing to sync before registration
            if (g.session.hasWorkSession) g.work.syncAll() else g.work.syncPolicy()
            Result.success()
        } catch (e: ApiException) {
            if (e.status in 401..403) Result.success() else Result.retry()
        } catch (e: Exception) {
            Result.retry()
        }
    }

    companion object {
        private val net = Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()

        fun schedulePeriodic(ctx: Context) {
            WorkManager.getInstance(ctx).enqueueUniquePeriodicWork("ammp-sync", ExistingPeriodicWorkPolicy.KEEP,
                PeriodicWorkRequestBuilder<SyncWorker>(15, TimeUnit.MINUTES).setConstraints(net)
                    .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS).build())
        }

        fun syncNow(ctx: Context) {
            WorkManager.getInstance(ctx).enqueueUniqueWork("ammp-sync-now", ExistingWorkPolicy.REPLACE,
                OneTimeWorkRequestBuilder<SyncWorker>().setConstraints(net).build())
        }
    }
}
