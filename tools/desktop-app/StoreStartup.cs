#if STORE
using Windows.ApplicationModel;
using Windows.ApplicationModel.Activation;

namespace MindCollector.Desktop;

/// <summary>
/// Starting with Windows in the Store build: the package's StartupTask (store/AppxManifest.xml,
/// TaskId MindCollectorStartup, on from the first launch as the downloaded app's Run key is), not
/// the registry. A player who turns it off in Task Manager can only turn it back on there; Windows
/// does not let the app overrule them, and Settings says so.
/// </summary>
internal static class StoreStartup
{
    private const string TaskId = "MindCollectorStartup";

    public static StartupTaskState State() => Run(async () => (await StartupTask.GetAsync(TaskId)).State, StartupTaskState.Disabled);

    public static bool IsOn() => State() is StartupTaskState.Enabled or StartupTaskState.EnabledByPolicy;

    public static void Set(bool on) => Run(async () =>
    {
        var task = await StartupTask.GetAsync(TaskId);
        if (on && task.State == StartupTaskState.Disabled) await task.RequestEnableAsync();
        else if (!on && task.State == StartupTaskState.Enabled) task.Disable();
        return true;
    }, false);

    /// <summary>Opened by Windows at sign-in, rather than by the player: start in the tray.</summary>
    public static bool LaunchedAtSignIn()
    {
        try { return AppInstance.GetActivatedEventArgs()?.Kind == ActivationKind.StartupTask; }
        catch { return false; }
    }

    // Off the UI thread, so waiting on WinRT never deadlocks the window's message loop.
    private static T Run<T>(Func<Task<T>> f, T fallback)
    {
        try { return Task.Run(f).GetAwaiter().GetResult(); }
        catch { return fallback; }
    }
}
#endif
