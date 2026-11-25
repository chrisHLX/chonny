using System.Globalization;
using System.Runtime.InteropServices;
using System.Text.Json;
using System.Text.Json.Nodes;

namespace MindCollector.Desktop;

/// <summary>
/// Renaming, starring, trimming and deleting a video or a Forever clip (0.6.36, Chriso: "allow users to
/// rename and organise their clips ... and trim them"; "pay particular attention to UI/UX").
///
/// - **A name and a star live in the clip's timing file** ("title", "starred"), never in the file's own
///   name: renaming the file would break the page playing it and the folder it's grouped by, and the
///   timing file already travels with the clip.
/// - **Trim** cuts with ClipCutter (Windows' own transcoder, frame-exact, on the graphics card) to a new
///   file beside it. Only once that's written does the original go to the **Recycle Bin**, with its timing
///   file, and the trim takes its name. The timing file's start moves by what was cut, so the round
///   buttons, a game's timeline (the website's Watch page lines up by it) and YouTube chapters stay right.
/// - **Delete** sends the clip and its timing file to the Recycle Bin, never deletes outright; a Forever
///   game's folder with no clips left goes too (its log lines are only a copy: forever-logs keeps them).
///
/// The page's side is <see cref="Snippet"/>: one block of CSS and script added to the app's player and to
/// the website's Watch page alike, so both have the same buttons, dialogs and trim panel. It talks to the
/// app with chrome.webview.postMessage ({type: "clip"}), and the app only ever acts on the clip the page
/// is showing (MainForm.OnClipMessage).
/// </summary>
internal static class ClipTools
{
    public const int MaxName = 80;

    /// <summary>The player's own name for the clip; empty or null puts the app's name back.</summary>
    public static void SetTitle(string dir, string name, string? title)
    {
        title = Clean(title);
        Edit(dir, name, node =>
        {
            if (title.Length == 0) node.Remove("title"); else node["title"] = title;
        });
    }

    public static void SetStarred(string dir, string name, bool on) =>
        Edit(dir, name, node => { if (on) node["starred"] = true; else node.Remove("starred"); });

    /// <summary>One line, no control characters, at most MaxName characters.</summary>
    public static string Clean(string? title)
    {
        var s = System.Text.RegularExpressions.Regex.Replace(new string((title ?? "").Select(c => char.IsControl(c) ? ' ' : c).ToArray()), @"\s+", " ").Trim();
        return s.Length > MaxName ? s[..MaxName].TrimEnd() : s;
    }

    private static void Edit(string dir, string name, Action<JsonObject> change)
    {
        var path = VideoLibrary.FullPath(dir, name) ?? throw new InvalidOperationException("that clip isn't in the videos folder");
        if (!File.Exists(path)) throw new FileNotFoundException("the clip is no longer in the videos folder");
        var sidecar = path + Recorder.SidecarSuffix;
        var node = Read(sidecar);
        change(node);
        File.WriteAllText(sidecar, node.ToJsonString(new JsonSerializerOptions { WriteIndented = true }));
    }

    private static JsonObject Read(string sidecar) =>
        File.Exists(sidecar) ? JsonNode.Parse(File.ReadAllText(sidecar)) as JsonObject ?? new JsonObject() : new JsonObject();

    /// <summary>
    /// Keeps <paramref name="from"/> to <paramref name="to"/> seconds of the clip. Null when it worked, else
    /// why not, said for the player. <paramref name="progress"/> is 0 to 100, on the transcoder's thread.
    /// </summary>
    public static async Task<string?> TrimAsync(string dir, VideoEntry v, double from, double to, Action<double> progress)
    {
        var path = VideoLibrary.FullPath(dir, v.Name);
        if (path == null || !File.Exists(path)) return "The clip is no longer in the videos folder.";
        var full = Mp4.Duration(path) ?? v.Length;
        if (full is not { } length || length <= TimeSpan.Zero) return "The clip's length couldn't be read, so it can't be trimmed.";

        from = Math.Clamp(from, 0, length.TotalSeconds);
        to = Math.Clamp(to, from, length.TotalSeconds);
        if (to - from < 1) return "Keep at least a second of the clip.";
        var fromStart = TimeSpan.FromSeconds(from);
        var fromEnd = length - TimeSpan.FromSeconds(to);
        if (fromEnd < TimeSpan.Zero) fromEnd = TimeSpan.Zero;
        if (fromStart.TotalSeconds < 0.05 && fromEnd.TotalSeconds < 0.05) return "Move the start or the end first: nothing would be cut.";

        var temp = Path.Combine(Path.GetDirectoryName(path)!, Path.GetFileNameWithoutExtension(path) + ".trimming" + Path.GetExtension(path));
        try
        {
            var why = await ClipCutter.CutAsync(path, temp, fromStart, fromEnd, length, progress);
            if (why != null) { TryDelete(temp); return $"Windows couldn't trim it: {why}."; }
            if (!File.Exists(temp)) return "The trimmed clip wasn't written.";
            var kept = Mp4.Duration(temp) ?? length - fromStart - fromEnd;

            var sidecar = path + Recorder.SidecarSuffix;
            var node = Read(sidecar);
            var started = Clock(node, "recordingStarted") ?? v.Started;

            // The original and its timing file to the Recycle Bin, then the trim takes their place.
            if (!await RecycleAsync(path))
            {
                TryDelete(temp);
                return "The original couldn't be moved to the Recycle Bin. Is it open in another program?";
            }
            if (File.Exists(sidecar)) await RecycleAsync(sidecar);
            File.Move(temp, path);

            var newStart = started + fromStart;
            node["recordingStarted"] = Clock(newStart);
            node["recordingStopped"] = Clock(newStart + kept);
            node["trimmed"] = new JsonObject
            {
                ["at"] = DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss", CultureInfo.InvariantCulture),
                ["from"] = Math.Round(from, 2),
                ["to"] = Math.Round(to, 2),
                ["originalSeconds"] = Math.Round(length.TotalSeconds, 2),
            };
            File.WriteAllText(sidecar, node.ToJsonString(new JsonSerializerOptions { WriteIndented = true }));
            return null;
        }
        catch (Exception e)
        {
            TryDelete(temp);
            return $"The trim failed: {e.Message}";
        }
    }

    /// <summary>The clip and its timing file to the Recycle Bin, and its Forever game folder when no clip is left in it.</summary>
    public static async Task<string?> DeleteAsync(string dir, VideoEntry v)
    {
        var path = VideoLibrary.FullPath(dir, v.Name);
        if (path == null || !File.Exists(path)) return null;   // gone already
        if (!await RecycleAsync(path)) return "Windows wouldn't move it to the Recycle Bin. Is it open in another program?";
        var sidecar = path + Recorder.SidecarSuffix;
        if (File.Exists(sidecar)) await RecycleAsync(sidecar);

        var folder = Path.GetDirectoryName(path)!;
        if (!string.Equals(Path.GetFullPath(folder).TrimEnd('\\'), Path.GetFullPath(dir).TrimEnd('\\'), StringComparison.OrdinalIgnoreCase)
            && Path.GetFileName(folder).StartsWith(VideoLibrary.ForeverFolderPrefix, StringComparison.Ordinal))
        {
            try { if (!Directory.EnumerateFiles(folder).Any(VideoLibrary.IsVideo)) await RecycleAsync(folder); }
            catch { /* the folder stays: harmless */ }
        }
        return null;
    }

    // ---------------------------------------------------------------------------------- the Recycle Bin

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct ShFileOp
    {
        public IntPtr Hwnd;
        public uint Func;
        public string From;
        public string? To;
        public ushort Flags;
        [MarshalAs(UnmanagedType.Bool)] public bool AnyAborted;
        public IntPtr NameMappings;
        public string? ProgressTitle;
    }

    [DllImport("shell32.dll", CharSet = CharSet.Unicode)]
    private static extern int SHFileOperation(ref ShFileOp op);

    /// <summary>
    /// To the Recycle Bin, silently. A video the page has only just let go of can still be held for a
    /// moment, so it tries for about three seconds.
    /// </summary>
    private static async Task<bool> RecycleAsync(string path)
    {
        const uint delete = 3;
        const ushort silent = 0x4, noConfirmation = 0x10, allowUndo = 0x40, noErrorUi = 0x400;
        for (var i = 0; i < 10; i++)
        {
            if (!File.Exists(path) && !Directory.Exists(path)) return true;
            var op = new ShFileOp { Func = delete, From = path + "\0", Flags = (ushort)(silent | noConfirmation | allowUndo | noErrorUi) };
            var rc = SHFileOperation(ref op);
            if (rc == 0 && !op.AnyAborted && !File.Exists(path) && !Directory.Exists(path)) return true;
            await Task.Delay(300);
        }
        return false;
    }

    private static void TryDelete(string path) { try { if (File.Exists(path)) File.Delete(path); } catch { } }

    private static string Clock(DateTime t) => t.ToString("yyyy-MM-dd HH:mm:ss.fff", CultureInfo.InvariantCulture);

    private static DateTime? Clock(JsonObject node, string name) =>
        node[name] is JsonValue val && val.TryGetValue<string>(out var s)
            && DateTime.TryParse(s, CultureInfo.InvariantCulture, DateTimeStyles.None, out var t) ? t : null;

    // ---------------------------------------------------------------------------------- the page's side

    /// <summary>
    /// The clip's buttons, dialogs and trim panel, for the app's player (VideoLibrary.Page) and the
    /// website's Watch page (added by MainForm.ShowVideo). <paramref name="original"/> is the app's name for
    /// it; <paramref name="tool"/> opens "rename", "trim" or "delete" at once; <paramref name="toast"/> is a
    /// line to show as the page opens.
    /// </summary>
    public static string Snippet(VideoEntry v, string original, string? tool = null, string? toast = null)
    {
        var data = JsonSerializer.Serialize(new
        {
            name = v.Name,
            title = v.Custom ?? "",
            original,
            starred = v.Starred,
            mb = Math.Round(v.Bytes / 1048576.0),
            length = v.Length?.TotalSeconds,
            tool,
            toast,
            max = MaxName,
        });
        // JSON inside a script: "</" would end it.
        return "<script>window.MC_CLIP=" + data.Replace("</", "<\\/") + ";</script>" + Css + Script;
    }

    private const string Css = """
        <style>
        .mc-b{display:inline-flex;align-items:center;gap:7px;border:0;border-radius:999px;padding:8px 14px;margin-left:6px;font:700 13px 'Segoe UI',Arial,sans-serif;line-height:1.15;cursor:pointer;background:#2A2A2A;color:#fff;vertical-align:middle;white-space:nowrap}
        .mc-b:hover{background:#3E3E3E}
        .mc-b:focus-visible,.mc-h:focus-visible{outline:2px solid #E8B84B;outline-offset:2px}
        .mc-b .i{font-family:'Segoe Fluent Icons','Segoe MDL2 Assets';font-weight:400;font-size:14px;line-height:1}
        .mc-b.on .i{color:#E8B84B}
        .mc-b.del:hover{background:#3E181A;color:#FCA5A5}
        .mc-b.gold{background:#C8952C;color:#000}.mc-b.gold:hover{background:#E8B84B}
        .mc-b.red{background:#B3261E;color:#fff}.mc-b.red:hover{background:#D3362C}
        .mc-b[disabled]{opacity:.35;cursor:default;pointer-events:none}
        .mc-b.sm{padding:6px 12px;font-size:12.5px}
        .applinks .mc-b{padding:6px 13px;font-size:12.5px}
        #mc-clip-actions{display:inline-flex;flex-wrap:wrap;gap:0}
        #mc-title{cursor:pointer;border-radius:6px}
        #mc-title:hover{background:#1E1E1E;box-shadow:0 0 0 6px #1E1E1E}
        #mc-title .pen{font-family:'Segoe Fluent Icons','Segoe MDL2 Assets';font-size:15px;font-weight:400;color:#6A6A6A;margin-left:10px;opacity:0;vertical-align:middle}
        #mc-title:hover .pen{opacity:1}
        .mc-veil{position:fixed;inset:0;background:rgba(0,0,0,.62);display:flex;align-items:center;justify-content:center;z-index:9999;animation:mcfade .12s ease-out}
        .mc-card{background:#1E1E1E;border-radius:12px;padding:22px 22px 18px;width:min(460px,90vw);box-shadow:0 24px 70px rgba(0,0,0,.65);color:#fff;font-family:'Segoe UI',Arial,sans-serif;animation:mcpop .14s ease-out}
        .mc-card h3{margin:0 0 6px;font-size:18px;font-weight:800;letter-spacing:-.01em}
        .mc-card p{margin:0 0 14px;color:#B3B3B3;font-size:13.5px;line-height:1.5}
        .mc-card p b{color:#fff}
        .mc-card input{width:100%;box-sizing:border-box;background:#2A2A2A;border:1px solid #3E3E3E;border-radius:8px;color:#fff;font:15px 'Segoe UI',Arial,sans-serif;padding:10px 12px;outline:none}
        .mc-card input:focus{border-color:#C8952C}
        .mc-count{color:#6A6A6A;font-size:11.5px;text-align:right;margin-top:5px;font-variant-numeric:tabular-nums}
        .mc-row{display:flex;gap:2px;justify-content:flex-end;align-items:center;margin-top:16px}
        .mc-link{background:none;border:0;color:#B3B3B3;font:13px 'Segoe UI',Arial,sans-serif;cursor:pointer;margin-right:auto;padding:0;text-decoration:underline;text-underline-offset:3px}
        .mc-link:hover{color:#fff}
        .mc-trim{margin-top:14px;background:#181818;border-radius:10px;padding:14px 16px 14px;font-family:'Segoe UI',Arial,sans-serif;color:#fff;animation:mcfade .12s ease-out}
        .wrap>.mc-trim:first-child{margin:0 0 14px}
        .mc-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
        .mc-top b{font-size:15px}
        .mc-top .mc-hint{color:#6A6A6A;font-size:12px;margin-left:10px;font-weight:400}
        .mc-x{background:none;border:0;color:#B3B3B3;font-family:'Segoe Fluent Icons','Segoe MDL2 Assets';font-size:12px;cursor:pointer;padding:6px;border-radius:6px}
        .mc-x:hover{background:#2A2A2A;color:#fff}
        .mc-track{position:relative;height:44px;background:#2A2A2A;border-radius:6px;cursor:pointer;user-select:none;touch-action:none;margin:0 6px}
        .mc-out{position:absolute;top:0;bottom:0;background:repeating-linear-gradient(135deg,#1a1a1a 0 6px,#202020 6px 12px);opacity:.92}
        .mc-out.l{border-radius:6px 0 0 6px}.mc-out.r{border-radius:0 6px 6px 0}
        .mc-sel{position:absolute;top:0;bottom:0;box-sizing:border-box;border-top:2px solid #C8952C;border-bottom:2px solid #C8952C;background:rgba(200,149,44,.14)}
        .mc-h{position:absolute;top:-5px;bottom:-5px;width:14px;margin-left:-7px;background:#C8952C;border-radius:5px;cursor:ew-resize;outline:none;z-index:2}
        .mc-h:after{content:'';position:absolute;left:6px;top:16px;bottom:16px;width:2px;background:rgba(0,0,0,.45);border-radius:1px}
        .mc-h:hover,.mc-h.drag{background:#E8B84B}
        .mc-ph{position:absolute;top:-7px;bottom:-7px;width:2px;margin-left:-1px;background:#fff;pointer-events:none;border-radius:1px;z-index:1;box-shadow:0 0 0 1px rgba(0,0,0,.4)}
        .mc-scale{display:flex;justify-content:space-between;color:#6A6A6A;font-size:11.5px;margin:7px 6px 0;font-variant-numeric:tabular-nums}
        .mc-mid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-top:12px}
        .mc-cell{background:#1E1E1E;border-radius:8px;padding:9px 12px;display:flex;align-items:center;justify-content:space-between;gap:8px;min-width:0}
        .mc-cell .k{color:#B3B3B3;font-size:12px}
        .mc-cell .t{font-size:17px;font-weight:700;font-variant-numeric:tabular-nums;display:block;margin-top:1px}
        .mc-cell.keep .t{color:#E8B84B}
        .mc-cell .mc-b{margin-left:0}
        .mc-kbd{display:inline-block;min-width:16px;text-align:center;border:1px solid #4A4A4A;border-bottom-width:2px;border-radius:4px;padding:0 4px;font:600 11px 'Segoe UI',Arial,sans-serif;color:#B3B3B3;margin-left:2px}
        .mc-foot{display:flex;align-items:center;gap:2px;margin-top:14px}
        .mc-note{color:#6A6A6A;font-size:12px;margin-right:auto;line-height:1.4;padding-right:12px}
        .mc-busy{padding:6px 2px 2px}
        .mc-busy .k{color:#B3B3B3;font-size:13.5px}
        .mc-bar{height:6px;background:#2A2A2A;border-radius:3px;overflow:hidden;margin-top:10px}
        .mc-bar i{display:block;height:100%;width:0;background:#C8952C;transition:width .25s}
        .mc-err{color:#FCA5A5;font-size:13px;margin-top:10px;line-height:1.45}
        .mc-toast{position:fixed;left:50%;bottom:26px;transform:translateX(-50%);background:#2A2A2A;color:#fff;padding:10px 18px;border-radius:999px;font:600 13px 'Segoe UI',Arial,sans-serif;box-shadow:0 10px 34px rgba(0,0,0,.55);z-index:10000;transition:opacity .35s;display:flex;gap:8px;align-items:center}
        .mc-toast .i{font-family:'Segoe Fluent Icons','Segoe MDL2 Assets';font-weight:400;color:#86EFAC}
        @keyframes mcfade{from{opacity:0}to{opacity:1}}
        @keyframes mcpop{from{opacity:0;transform:translateY(6px) scale(.98)}to{opacity:1;transform:none}}
        @media (max-width:760px){.mc-mid{grid-template-columns:1fr}}
        </style>
        """;

    private const string Script = """
        <script>
        (function () {
          var C = window.MC_CLIP; if (!C) return;
          var vid = document.getElementById('vid') || document.querySelector('video');
          var watch = !!document.querySelector('.head .applinks');
          var I = { star: '', starOn: '', edit: '', trim: '', del: '', play: '', stop: '', close: '', ok: '' };

          function post(o) { o.type = 'clip'; o.name = C.name; try { window.chrome.webview.postMessage(o); } catch (e) {} }
          function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }
          function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
          function fmt(t, tenths) {
            t = Math.max(0, t || 0);
            var m = Math.floor(t / 60), s = t - m * 60;
            var ss = tenths ? s.toFixed(1) : String(Math.floor(s));
            if (s < 10) ss = '0' + ss;
            return m + ':' + ss;
          }
          function typing(e) { var t = e.target; return t && (/input|textarea|select/i.test(t.tagName) || t.isContentEditable); }
          function shown() { return C.title || C.original; }

          var toastTimer;
          function toast(text) {
            var old = document.querySelector('.mc-toast'); if (old) old.remove();
            var t = el('div', 'mc-toast', '<span class="i">' + I.ok + '</span>' + esc(text));
            document.body.appendChild(t);
            clearTimeout(toastTimer);
            toastTimer = setTimeout(function () { t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 400); }, 3200);
          }

          // ---- the buttons
          function button(icon, label, cls, tip) {
            var b = el('button', 'mc-b' + (cls ? ' ' + cls : ''));
            b.type = 'button';
            b.innerHTML = '<span class="i">' + icon + '</span><span class="l">' + esc(label) + '</span>';
            if (tip) b.title = tip;
            return b;
          }
          var starB = button(I.star, 'Star', '', 'Keep it in Starred');
          var renB = button(I.edit, 'Rename', '', 'Rename (F2)');
          var trimB = button(I.trim, 'Trim', '', 'Cut off the start or the end');
          var delB = button(I.del, 'Delete', 'del', 'Delete (Del)');
          function drawStar() {
            starB.classList.toggle('on', !!C.starred);
            starB.querySelector('.i').textContent = C.starred ? I.starOn : I.star;
            starB.querySelector('.l').textContent = C.starred ? 'Starred' : 'Star';
            starB.title = C.starred ? 'Remove the star' : 'Keep it in Starred';
            starB.setAttribute('aria-pressed', C.starred ? 'true' : 'false');
          }
          var host = document.getElementById('mc-clip-actions');
          if (host) { [starB, renB, trimB, delB].forEach(function (b) { host.appendChild(b); }); }
          else {
            host = document.querySelector('.head .applinks');
            if (host) [delB, trimB, renB, starB].forEach(function (b) { host.insertBefore(b, host.firstChild); });
          }
          drawStar();

          // ---- the title
          var titleEl = watch ? document.querySelector('.head .title') : document.getElementById('mc-title');
          var origEl = document.getElementById('mc-orig');
          function drawTitle() {
            if (!titleEl) return;
            if (watch) { titleEl.textContent = C.title || 'Watch'; }
            else {
              titleEl.textContent = shown();
              titleEl.appendChild(el('span', 'pen', I.edit));
              if (origEl) { origEl.textContent = C.original; origEl.style.display = C.title ? '' : 'none'; }
            }
          }
          if (titleEl && !watch) { titleEl.title = 'Rename (F2)'; titleEl.onclick = function () { rename(); }; }
          drawTitle();

          starB.onclick = function () {
            C.starred = !C.starred; drawStar();
            post({ action: 'star', on: C.starred });
            toast(C.starred ? 'Starred' : 'Star removed');
          };

          // ---- dialogs
          var open = null;
          function dialog(html, onKey) {
            close();
            var v = el('div', 'mc-veil'), card = el('div', 'mc-card', html);
            v.appendChild(card);
            v.setAttribute('role', 'dialog'); v.setAttribute('aria-modal', 'true');
            v.addEventListener('mousedown', function (e) { if (e.target === v) close(); });
            // keys stay in the dialog: the Watch page steps its timeline on , and .
            v.addEventListener('keydown', function (e) {
              e.stopPropagation();
              if (e.key === 'Escape') { e.preventDefault(); close(); return; }
              if (onKey) onKey(e);
            });
            document.body.appendChild(v);
            open = v;
            return card;
          }
          function close() { if (open) { open.remove(); open = null; } }

          function rename() {
            var card = dialog(
              '<h3>Rename</h3><p>Shown in your list and on this page. The file on disk keeps its own name.</p>' +
              '<input type="text" spellcheck="false" autocomplete="off" maxlength="' + C.max + '" aria-label="Name">' +
              '<div class="mc-count"></div>' +
              '<div class="mc-row">' + (C.title ? '<button type="button" class="mc-link" data-r>Use the original name</button>' : '') +
              '<button type="button" class="mc-b" data-c>Cancel</button><button type="button" class="mc-b gold" data-s>Save</button></div>',
              function (e) { if (e.key === 'Enter') { e.preventDefault(); save(input.value); } });
            var input = card.querySelector('input'), count = card.querySelector('.mc-count');
            input.value = shown(); input.placeholder = C.original;
            function counted() { count.textContent = input.value.length + ' / ' + C.max; }
            input.addEventListener('input', counted); counted();
            setTimeout(function () { input.focus(); input.select(); }, 0);
            function save(t) {
              t = (t || '').replace(/\s+/g, ' ').trim();
              if (t === C.original) t = '';
              var changed = t !== (C.title || '');
              C.title = t; drawTitle(); close();
              if (!changed) return;
              post({ action: 'rename', title: t });
              toast(t ? 'Renamed' : 'Back to the original name');
            }
            card.querySelector('[data-s]').onclick = function () { save(input.value); };
            card.querySelector('[data-c]').onclick = close;
            var r = card.querySelector('[data-r]'); if (r) r.onclick = function () { save(''); };
          }

          var savedSrc = null;
          function letGo() {
            // The app moves the file to the Recycle Bin: the player lets go of it first.
            if (!vid) return;
            try { vid.pause(); } catch (e) {}
            savedSrc = vid.currentSrc || vid.getAttribute('src');
            vid.removeAttribute('src'); vid.load();
          }
          function takeBack() { if (vid && savedSrc) { vid.src = savedSrc; vid.load(); } }

          function remove() {
            var facts = [];
            if (C.length) facts.push(fmt(C.length));
            if (C.mb) facts.push(C.mb + ' MB');
            var card = dialog(
              '<h3>Delete this clip?</h3><p><b>' + esc(shown()) + '</b>' + (facts.length ? ' · ' + facts.join(' · ') : '') +
              '<br>It goes to the Recycle Bin, so you can get it back from there.</p>' +
              '<div class="mc-row"><button type="button" class="mc-b" data-c>Cancel</button><button type="button" class="mc-b red" data-d>Delete</button></div>');
            var d = card.querySelector('[data-d]');
            card.querySelector('[data-c]').onclick = close;
            d.onclick = function () {
              d.disabled = true; d.textContent = 'Deleting…';
              card.querySelector('[data-c]').disabled = true;
              letGo();
              post({ action: 'delete' });
            };
            setTimeout(function () { d.focus(); }, 0);
          }

          // ---- trim
          var panel = null, raf = 0;
          function trim() {
            if (panel) { panel.querySelector('.mc-h').focus(); return; }
            if (!vid || !/mindcollector\.invalid/.test(vid.currentSrc || vid.getAttribute('src') || '')) {
              toast('Trim needs the video on this PC'); return;
            }
            if (vid.error || vid.networkState === 3) { toast("This video won't open here, so it can't be trimmed"); return; }
            if (!(vid.duration > 0) || !isFinite(vid.duration)) {
              // not loaded yet: open the panel once it is, or say why not
              trimB.disabled = true;
              var gaveUp = setTimeout(function () { trimB.disabled = false; vid.removeEventListener('loadedmetadata', once); toast("The video didn't load, so it can't be trimmed yet"); }, 6000);
              var once = function () { clearTimeout(gaveUp); trimB.disabled = false; vid.removeEventListener('loadedmetadata', once); trim(); };
              vid.addEventListener('loadedmetadata', once);
              if (vid.readyState === 0) { try { vid.load(); } catch (e) {} }
              return;
            }
            var d = vid.duration, a = 0, b = d, MIN = 1, drag = null, preview = false;
            panel = el('div', 'mc-trim',
              '<div class="mc-top"><span><b>Trim</b><span class="mc-hint">Drag the gold handles, or play to a moment and press <span class="mc-kbd">I</span> for the start and <span class="mc-kbd">O</span> for the end</span></span>' +
              '<button type="button" class="mc-x" title="Close (Esc)" aria-label="Close">' + I.close + '</button></div>' +
              '<div class="mc-track"><div class="mc-out l"></div><div class="mc-out r"></div><div class="mc-sel"></div>' +
              '<div class="mc-h" data-h="a" tabindex="0" role="slider" aria-label="Start"></div><div class="mc-h" data-h="b" tabindex="0" role="slider" aria-label="End"></div><div class="mc-ph"></div></div>' +
              '<div class="mc-scale"><span>0:00</span><span>' + fmt(d) + '</span></div>' +
              '<div class="mc-mid">' +
              '<div class="mc-cell"><span><span class="k">Start</span><span class="t" data-t="a"></span></span><button type="button" class="mc-b sm" data-set="a" title="Start here (I)">Set to here <span class="mc-kbd">I</span></button></div>' +
              '<div class="mc-cell"><span><span class="k">End</span><span class="t" data-t="b"></span></span><button type="button" class="mc-b sm" data-set="b" title="End here (O)">Set to here <span class="mc-kbd">O</span></button></div>' +
              '<div class="mc-cell keep"><span><span class="k">Keeps</span><span class="t" data-t="k"></span></span><span class="k" data-t="cut"></span></div>' +
              '</div>' +
              '<div class="mc-foot"><span class="mc-note">Saving replaces this clip with the part between the handles. The original goes to the Recycle Bin.</span>' +
              '<button type="button" class="mc-b" data-p><span class="i">' + I.play + '</span><span class="l">Preview</span></button>' +
              '<button type="button" class="mc-b" data-c>Cancel</button><button type="button" class="mc-b gold" data-s>Save trim</button></div>' +
              '<div class="mc-err" style="display:none"></div>');
            // under the buttons on the app's player; at the top of the Watch page, above its video
            var bar = document.getElementById('mc-bar'), wrap = document.querySelector('.wrap');
            if (!watch && bar) bar.parentNode.insertBefore(panel, bar.nextSibling);
            else if (wrap) wrap.insertBefore(panel, wrap.firstChild);
            else document.body.appendChild(panel);

            var track = panel.querySelector('.mc-track'), sel = panel.querySelector('.mc-sel'),
                outL = panel.querySelector('.mc-out.l'), outR = panel.querySelector('.mc-out.r'),
                hA = panel.querySelector('[data-h=a]'), hB = panel.querySelector('[data-h=b]'), ph = panel.querySelector('.mc-ph'),
                tA = panel.querySelector('[data-t=a]'), tB = panel.querySelector('[data-t=b]'), tK = panel.querySelector('[data-t=k]'), tCut = panel.querySelector('[data-t=cut]'),
                saveB = panel.querySelector('[data-s]'), prevB = panel.querySelector('[data-p]'), err = panel.querySelector('.mc-err');
            function pc(t) { return (t / d * 100) + '%'; }
            function draw() {
              outL.style.left = '0'; outL.style.width = pc(a);
              outR.style.left = pc(b); outR.style.width = pc(d - b);
              sel.style.left = pc(a); sel.style.width = pc(b - a);
              hA.style.left = pc(a); hB.style.left = pc(b);
              tA.textContent = fmt(a, true); tB.textContent = fmt(b, true); tK.textContent = fmt(b - a, true);
              var cut = a + (d - b);
              tCut.textContent = cut >= 0.05 ? 'cuts ' + fmt(cut, true) : 'nothing cut yet';
              hA.setAttribute('aria-valuenow', a.toFixed(1)); hA.setAttribute('aria-valuetext', fmt(a, true));
              hB.setAttribute('aria-valuenow', b.toFixed(1)); hB.setAttribute('aria-valuetext', fmt(b, true));
              saveB.disabled = !(b - a >= MIN && cut >= 0.05);
            }
            function head() { ph.style.left = pc(Math.min(d, vid.currentTime || 0)); }
            function loop() {
              head();
              if (preview && vid.currentTime >= b - 0.03) { vid.pause(); stopPreview(); }
              raf = requestAnimationFrame(loop);
            }
            function seek(t) { try { vid.currentTime = Math.max(0, Math.min(d, t)); } catch (e) {} head(); }
            function setA(t) { a = Math.max(0, Math.min(t, b - MIN)); draw(); }
            function setB(t) { b = Math.min(d, Math.max(t, a + MIN)); draw(); }
            // I and O (and Set to here): a start after the end moves the end out to the clip's end, and an
            // end before the start moves the start back to 0, as video editors do; dragging only clamps.
            function markA(t) { if (t > b - MIN) b = d; setA(t); }
            function markB(t) { if (t < a + MIN) a = 0; setB(t); }
            function at(x) { var r = track.getBoundingClientRect(); return Math.max(0, Math.min(d, (x - r.left) / r.width * d)); }
            function stopPreview() { preview = false; prevB.querySelector('.i').textContent = I.play; prevB.querySelector('.l').textContent = 'Preview'; }

            [hA, hB].forEach(function (h) {
              h.addEventListener('pointerdown', function (e) {
                e.preventDefault(); e.stopPropagation();
                h.setPointerCapture(e.pointerId); h.focus(); h.classList.add('drag');
                drag = h.getAttribute('data-h'); vid.pause(); stopPreview();
              });
              h.addEventListener('pointermove', function (e) {
                if (drag !== h.getAttribute('data-h')) return;
                var t = at(e.clientX);
                if (drag === 'a') { setA(t); seek(a); } else { setB(t); seek(b); }
              });
              h.addEventListener('pointerup', function () { drag = null; h.classList.remove('drag'); });
              h.addEventListener('keydown', function (e) {
                var step = e.shiftKey ? 1 : 0.1, which = h.getAttribute('data-h'), now = which === 'a' ? a : b, t = null;
                if (e.key === 'ArrowLeft' || e.key === 'ArrowDown') t = now - step;
                else if (e.key === 'ArrowRight' || e.key === 'ArrowUp') t = now + step;
                else if (e.key === 'Home') t = 0;
                else if (e.key === 'End') t = d;
                if (t === null) return;
                e.preventDefault(); e.stopPropagation();
                if (which === 'a') { setA(t); seek(a); } else { setB(t); seek(b); }
              });
            });
            var scrubbing = false;
            track.addEventListener('pointerdown', function (e) {
              if (e.target.classList.contains('mc-h')) return;
              track.setPointerCapture(e.pointerId); scrubbing = true; stopPreview(); seek(at(e.clientX));
            });
            track.addEventListener('pointermove', function (e) { if (scrubbing) seek(at(e.clientX)); });
            track.addEventListener('pointerup', function () { scrubbing = false; });

            panel.querySelector('[data-set=a]').onclick = function () { markA(vid.currentTime || 0); };
            panel.querySelector('[data-set=b]').onclick = function () { markB(vid.currentTime || 0); };
            prevB.onclick = function () {
              if (preview) { vid.pause(); stopPreview(); return; }
              seek(a); preview = true;
              prevB.querySelector('.i').textContent = I.stop; prevB.querySelector('.l').textContent = 'Stop';
              var p = vid.play(); if (p && p.catch) p.catch(function () { stopPreview(); });
            };
            panel.querySelector('[data-c]').onclick = closeTrim;
            panel.querySelector('.mc-x').onclick = closeTrim;
            saveB.onclick = function () {
              stopPreview();
              var keep = b - a;
              panel.querySelector('.mc-foot').style.display = 'none';
              err.style.display = 'none';
              track.style.pointerEvents = 'none'; track.style.opacity = '.55';
              panel.querySelectorAll('.mc-mid .mc-b').forEach(function (x) { x.disabled = true; });
              var busy = el('div', 'mc-busy', '<span class="k">Trimming to ' + fmt(keep, true) + '… <span data-pc>0%</span></span><div class="mc-bar"><i></i></div>');
              panel.appendChild(busy);
              letGo();
              post({ action: 'trim', from: a, to: b, length: d });
            };
            vid.addEventListener('seeked', head);
            panel.onProgress = function (p) {
              var bar = panel.querySelector('.mc-bar i'), t = panel.querySelector('[data-pc]');
              if (bar) bar.style.width = Math.max(2, Math.min(100, p)) + '%';
              if (t) t.textContent = Math.round(p) + '%';
            };
            panel.onError = function (message) {
              var busy = panel.querySelector('.mc-busy'); if (busy) busy.remove();
              panel.querySelector('.mc-foot').style.display = '';
              track.style.pointerEvents = ''; track.style.opacity = '';
              panel.querySelectorAll('.mc-mid .mc-b').forEach(function (x) { x.disabled = false; });
              err.textContent = message; err.style.display = '';
              takeBack();
              vid.addEventListener('loadedmetadata', function once() { vid.removeEventListener('loadedmetadata', once); seek(a); });
            };
            panel.keys = function (e) {
              if (typing(e) || e.ctrlKey || e.altKey || e.metaKey || panel.querySelector('.mc-busy')) return false;
              var k = e.key.toLowerCase();
              if (k === 'escape') { closeTrim(); return true; }
              if (k === 'i') { markA(vid.currentTime || 0); return true; }
              if (k === 'o') { markB(vid.currentTime || 0); return true; }
              return false;
            };
            draw(); head(); loop();
            if (vid.currentTime > 0) head();
            panel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            setTimeout(function () { hA.focus({ preventScroll: true }); }, 0);
          }
          function closeTrim() {
            if (!panel) return;
            cancelAnimationFrame(raf);
            panel.remove(); panel = null;
          }
          trimB.onclick = trim;
          renB.onclick = rename;
          delB.onclick = remove;

          document.addEventListener('keydown', function (e) {
            if (open || typing(e)) return;
            if (panel && panel.keys && panel.keys(e)) { e.preventDefault(); e.stopPropagation(); return; }
            if (e.key === 'F2') { e.preventDefault(); rename(); }
            else if (e.key === 'Delete') { e.preventDefault(); remove(); }
          }, true);

          // ---- what the app says back
          try {
            window.chrome.webview.addEventListener('message', function (e) {
              var m = e.data; if (!m || typeof m !== 'object') return;
              if (m.type === 'clip-progress' && panel && panel.onProgress) panel.onProgress(m.p);
              else if (m.type === 'clip-error') {
                if (panel && panel.querySelector('.mc-busy')) panel.onError(m.message);
                else { close(); takeBack(); toast(m.message); }
              }
              else if (m.type === 'clip-open') { if (m.tool === 'rename') rename(); else if (m.tool === 'trim') trim(); else if (m.tool === 'delete') remove(); }
              else if (m.type === 'clip-star') { C.starred = !!m.on; drawStar(); }
            });
          } catch (e) {}

          if (C.toast) toast(C.toast);
          if (C.tool === 'rename') rename();
          else if (C.tool === 'delete') remove();
          else if (C.tool === 'trim') trim();
        })();
        </script>
        """;
}
