namespace MindCollector.Desktop;

/// <summary>
/// The app's own pages (2026-10-25), drawn here rather than by the website because they are about
/// the game being played right now: "This game", and the note box under a game's card. Both talk to
/// the window through WebView2's messages (window.chrome.webview), so typing is never interrupted:
/// the page is drawn once and the window sends it the game's state as it changes (MainForm).
///
/// Page to window: {type:"note", text, target:"live"|"next"|"game", lobby?, mark?} and {type:"mark"}.
/// Window to page: {type:"state", ...} (LiveGame.Snapshot plus the notes) and {type:"saved"}.
/// </summary>
internal static class ThisGamePage
{
    private const string Css = """
        html,body{margin:0;background:#121212;color:#fff;font-family:'Segoe UI',Arial,sans-serif}
        .wrap{padding:28px 32px;max-width:980px}
        .eyebrow{color:#C8952C;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
        h1{font-size:28px;font-weight:800;letter-spacing:-.02em;margin:6px 0 4px}
        .muted{color:#B3B3B3}.subtle{color:#6A6A6A}.small{font-size:12.5px}
        .clock{font-size:15px;color:#B3B3B3;margin-bottom:22px}
        .clock b{color:#fff;font-variant-numeric:tabular-nums}
        .teams{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:22px}
        .team{flex:1 1 300px;background:#181818;border-radius:8px;padding:12px 14px}
        .team h2{font-size:13px;color:#B3B3B3;margin:0 0 8px;font-weight:700}
        .p{display:flex;justify-content:space-between;align-items:baseline;padding:5px 0;border-top:1px solid #282828}
        .p:first-of-type{border-top:0}
        .p .n{font-weight:700}.p .s{font-size:12px;color:#B3B3B3;margin-left:6px}
        .p .x{font-size:12px;color:#B3B3B3;white-space:nowrap}
        .box{background:#181818;border-radius:8px;padding:14px}
        textarea{width:100%;box-sizing:border-box;min-height:64px;background:#232323;color:#fff;border:1px solid #3E3E3E;border-radius:6px;padding:9px 10px;font:14px 'Segoe UI',Arial;resize:vertical}
        textarea:focus{outline:none;border-color:#C8952C}
        .btns{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;align-items:center}
        button{border:0;border-radius:999px;padding:8px 16px;font-weight:700;font-size:13px;cursor:pointer;background:#2A2A2A;color:#fff}
        button:hover{background:#3E3E3E}
        button.gold{background:#C8952C;color:#000}button.gold:hover{background:#E8B84B}
        .notes{margin:14px 0 0;padding:0;list-style:none}
        .notes li{padding:6px 8px;border-radius:6px;font-size:13.5px;cursor:default}
        .notes li.mark{cursor:pointer}.notes li.mark:hover,.notes li.on{background:#232323}
        .notes .t{color:#B3B3B3;font-variant-numeric:tabular-nums;margin-right:8px}
        .notes .w{color:#6A6A6A;font-size:12px;margin-left:6px}
        .saved{color:#86efac;font-size:12.5px}
        """;

    /// <summary>"This game": drawn once, then filled by the window's state messages.</summary>
    public static string Html() => $$$"""
        <!doctype html><html><head><meta charset="utf-8"><style>{{{Css}}}</style></head><body><div class="wrap">
        <div class="eyebrow">This game</div>
        <h1 id="title">Not in an arena</h1>
        <div class="clock" id="clock">When you zone into an arena, the players show here as the gates open.</div>
        <div class="teams" id="teams"></div>
        <div class="box">
          <textarea id="text" placeholder="A note on this game. Enter saves it."></textarea>
          <div class="btns">
            <button class="gold" id="add">Add to this game</button>
            <button id="next">For next game</button>
            <button id="mark" title="Ctrl+Shift+M does this from inside WoW">Mark now</button>
            <span class="saved" id="saved"></span>
          </div>
          <ul class="notes" id="notes"></ul>
          <div class="small subtle" id="waiting"></div>
        </div>
        <p class="small subtle">Ctrl+Shift+M from inside WoW marks the moment. Click a mark here afterwards to write what happened.
        Notes reach the game's card on the website once the game has been sent.</p>
        </div><script>
        var S = {}, mark = null, since = null, at = Date.now();
        function el(id){return document.getElementById(id)}
        function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]})}
        function clock(s){s=Math.max(0,Math.floor(s));return Math.floor(s/60)+':'+('0'+s%60).slice(-2)}
        function tick(){
          if(S.state==='live'&&since!=null){el('clock').innerHTML='<b>'+clock(since+(Date.now()-at)/1000)+'</b> from the gates';}
        }
        function render(){
          var live=S.state==='prep'||S.state==='live'||S.state==='ended';
          var bits=[S.zone,S.bracket].filter(Boolean);
          if(S.shuffle&&S.round) bits.push('Round '+S.round);
          el('title').textContent=live?(bits.join(' · ')||'In an arena'):'Not in an arena';
          since=S.sinceGates; at=Date.now();
          if(S.state==='prep') el('clock').textContent='In the prep room. Both teams show as the gates open.';
          else if(S.state==='ended') el('clock').textContent='The game is over. Notes written in the next five minutes still count as this game\'s.';
          else if(!live) el('clock').textContent='When you zone into an arena, the players show here as the gates open.';
          tick();
          var us=[],them=[],other=[];
          (S.players||[]).forEach(function(p){
            var x=p.found===false?'no rating found':(p.found?((p.glad?p.glad+'× Glad · ':'')+(p.best?'best '+p.best:'no 3v3 rating')):(p.full?'looking up':''));
            var row='<div class="p"><span><span class="n" style="color:'+esc(p.color)+'">'+esc(p.name)+(p.you?' (you)':'')+'</span><span class="s">'+esc(p.spec)+'</span></span><span class="x">'+esc(x)+'</span></div>';
            (p.side==='us'?us:p.side==='them'?them:other).push(row);
          });
          el('teams').innerHTML=(us.length||other.length?'<div class="team"><h2>Your team</h2>'+us.concat(other).join('')+'</div>':'')+(them.length?'<div class="team"><h2>Enemy team</h2>'+them.join('')+'</div>':'');
          el('add').textContent=mark?'Write on this mark':(S.state==='live'||S.state==='ended')&&S.round?(S.shuffle?'Add to round '+S.round:'Add to this game'):S.state==='prep'?'Add to the next round':'Add as a note';
          var html='';
          (S.notes||[]).forEach(function(n){
            var on=mark===n.id?' on':'';
            html+='<li class="'+(n.kind==='mark'?'mark':'')+on+'" data-id="'+esc(n.id)+'"><span class="t">'+esc(n.when)+'</span>'+(n.kind==='mark'?(n.text?'Marked: '+esc(n.text):'Marked. Click to write what happened.'):esc(n.text))+(n.sent?'':'<span class="w">not sent yet</span>')+'</li>';
          });
          el('notes').innerHTML=html;
          Array.prototype.forEach.call(document.querySelectorAll('.notes li.mark'),function(li){
            li.onclick=function(){mark=mark===li.getAttribute('data-id')?null:li.getAttribute('data-id');render();el('text').focus();};
          });
          el('waiting').textContent=(S.waiting&&S.waiting.length)?S.waiting.length+' note(s) waiting for your next game: '+S.waiting.join(' | '):'';
        }
        function send(target){
          var t=el('text').value.trim();
          if(!t) return;
          window.chrome.webview.postMessage({type:'note',text:t,target:target,mark:target==='live'?mark:null});
          el('text').value='';mark=null;
        }
        el('add').onclick=function(){send('live')};
        el('next').onclick=function(){send('next')};
        el('mark').onclick=function(){window.chrome.webview.postMessage({type:'mark'})};
        el('text').addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send('live');}});
        window.chrome.webview.addEventListener('message',function(e){
          var m=e.data;
          if(m.type==='state'){S=m;render();}
          if(m.type==='saved'){el('saved').textContent='Saved';setTimeout(function(){el('saved').textContent=''},2500);}
        });
        setInterval(tick,1000);
        </script></body></html>
        """;

    /// <summary>
    /// The note box put under a game's card: a note on that game, or one for the next game. The card
    /// is the website's page; this is added by the window only, so the website's own copy of the
    /// page (on /wow/coach, with its own form) is unchanged.
    /// </summary>
    public static string NoteBar(string lobby) => $$$"""
        <style>
        #mc-notebar{position:fixed;left:0;right:0;bottom:0;z-index:50;background:#181818;border-top:1px solid #282828;padding:10px 16px;display:flex;gap:8px;align-items:center;font-family:'Segoe UI',Arial,sans-serif}
        #mc-notebar input{flex:1;background:#232323;color:#fff;border:1px solid #3E3E3E;border-radius:999px;padding:8px 14px;font:13.5px 'Segoe UI',Arial}
        #mc-notebar input:focus{outline:none;border-color:#C8952C}
        #mc-notebar button{border:0;border-radius:999px;padding:8px 14px;font-weight:700;font-size:13px;cursor:pointer;background:#2A2A2A;color:#fff}
        #mc-notebar button.gold{background:#C8952C;color:#000}
        #mc-notebar .ok{color:#86efac;font-size:12.5px;min-width:110px}
        body{padding-bottom:64px}
        </style>
        <div id="mc-notebar">
          <input id="mc-note" placeholder="Write a note on this game" maxlength="4000">
          <button class="gold" id="mc-add">Add to this game</button>
          <button id="mc-next">For next game</button>
          <span class="ok" id="mc-ok"></span>
        </div>
        <script>
        (function(){
          var box=document.getElementById('mc-note'),ok=document.getElementById('mc-ok');
          function send(target){var t=box.value.trim();if(!t)return;window.chrome.webview.postMessage({type:'note',text:t,target:target,lobby:'{{{lobby}}}'});box.value='';}
          document.getElementById('mc-add').onclick=function(){send('game')};
          document.getElementById('mc-next').onclick=function(){send('next')};
          box.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();send('game');}});
          window.chrome.webview.addEventListener('message',function(e){if(e.data&&e.data.type==='saved'){ok.textContent='Saved. It shows in the card\'s notes in a minute.';setTimeout(function(){ok.textContent=''},5000);}});
        })();
        </script>
        """;
}
