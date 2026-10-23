<figure class="fig" data-sim="stock-flow">
    <div class="fig-h"><b>A stock driven by one loop, or both</b>
        <div class="flex flex-wrap gap-1.5" role="group" aria-label="Loop type">
            <button class="seg-btn" type="button" data-mode="R">Reinforcing</button><button class="seg-btn" type="button" data-mode="B">Balancing</button><button class="seg-btn on" type="button" data-mode="L">Both: limits to growth</button>
        </div>
    </div>
    <div class="fig-b"><svg data-chart viewBox="0 0 720 240" style="width:100%;height:auto" role="img" aria-label="Stock over time"></svg></div>
    <div class="readout" data-read></div>
    <div class="ctrls">
        <div class="ctl"><label>Growth rate per step <output data-out="rate"></output></label><input type="range" data-in="rate" min="0.02" max="0.3" step="0.01" value="0.12" aria-label="Growth rate"></div>
        <div class="ctl"><label>Goal or capacity <output data-out="cap"></output></label><input type="range" data-in="cap" min="200" max="900" step="50" value="600" aria-label="Goal or capacity"></div>
    </div>
</figure>
