<figure class="fig" data-sim="thermostat">
    <div class="fig-h"><b>Thermostat simulator</b><span>1 step = 1 minute · heater limited to 2.5°C per minute · outside 10°C</span></div>
    <div class="fig-b"><svg data-chart viewBox="0 0 720 300" style="width:100%;height:auto" role="img" aria-label="Room temperature and heater output over time"></svg></div>
    <div class="readout" data-read></div>
    <div class="ctrls">
        <div class="ctl"><label>Proportional gain <output data-out="kp"></output></label><input type="range" data-in="kp" min="0.1" max="3" step="0.1" value="0.3" aria-label="Proportional gain"></div>
        <div class="ctl"><label>Integral gain <output data-out="ki"></output></label><input type="range" data-in="ki" min="0" max="0.1" step="0.005" value="0" aria-label="Integral gain"></div>
        <div class="ctl"><label>Sensor delay <output data-out="delay"></output></label><input type="range" data-in="delay" min="0" max="12" step="1" value="1" aria-label="Sensor delay"></div>
        <div class="ctl" style="justify-content:flex-end;gap:10px">
            <label class="tog"><input type="checkbox" data-in="dist" checked> Window opens at minute 120</label>
            <button class="seg-btn" type="button" data-reset>Reset to defaults</button>
        </div>
    </div>
    <ul class="tasks">
        <li data-t="sse"><i></i><span>Read the gap. With integral gain at zero the room settles below 21°C. Raise proportional gain to 0.6–0.8: the gap shrinks but doesn't disappear. Past 1, the room starts to swing even with a one-minute delay.</span></li>
        <li data-t="osc"><i></i><span>Break it. Set the sensor delay to 6 minutes or more with a proportional gain of 1 or more. The room swings by more than 4°C.</span></li>
        <li data-t="fix"><i></i><span>Fix it. Find settings where the room ends within 0.3°C of 21°C and never goes above 23°C.</span></li>
    </ul>
</figure>
