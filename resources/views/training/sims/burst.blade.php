<figure class="fig" data-sim="burst">
    <div class="fig-h"><b>Commitment, defensives and the kill</b><span>teaching model · invented numbers · one target, 180 seconds</span></div>
    <div class="fig-b">
        <svg data-chart viewBox="0 0 720 300" style="width:100%;height:auto" role="img" aria-label="Enemy target health and defensive cooldowns over time"></svg>
    </div>
    <div class="readout" data-read></div>
    <div class="ctrls">
        <div class="ctl"><label>Commitment per go <output data-out="commit"></output></label><input type="range" data-in="commit" min="0.1" max="1" step="0.05" value="0.5" aria-label="Commitment per go"></div>
        <div class="ctl"><label>Enemy healing per second <output data-out="heal"></output></label><input type="range" data-in="heal" min="2.5" max="6" step="0.5" value="4" aria-label="Enemy healing per second"></div>
        <div class="ctl"><label>Their defensive recharge <output data-out="defCd"></output></label><input type="range" data-in="defCd" min="60" max="150" step="10" value="90" aria-label="Defensive recharge time"></div>
        <div class="ctl"><label>Your go every <output data-out="goEvery"></output></label><input type="range" data-in="goEvery" min="30" max="60" step="5" value="45" aria-label="Time between your goes"></div>
    </div>
    <div class="fig-b" style="border-top:1px solid var(--t-line)">
        <p class="label" style="margin-bottom:6px">Sweep: time to kill at every commitment level, other settings as above</p>
        <svg data-sweep viewBox="0 0 720 170" style="width:100%;height:auto" role="img" aria-label="Time to kill by commitment level"></svg>
    </div>
</figure>
