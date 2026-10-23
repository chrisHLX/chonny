<figure class="fig" data-sim="loop-diagram">
    <div class="fig-h"><b>The closed loop</b><div class="flex flex-wrap gap-1.5" role="group" aria-label="Example system" data-examples></div></div>
    <div class="fig-b">
        <div class="scroll-x"><svg viewBox="0 0 760 250" style="width:100%;min-width:560px;height:auto" role="group" aria-label="Control loop diagram">
            <defs>
                <marker id="lp-ah" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z" fill="var(--t-ink-3)"/></marker>
                <marker id="lp-ahb" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z" fill="var(--t-b)"/></marker>
                <marker id="lp-ahr" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z" fill="var(--t-bad)"/></marker>
            </defs>
            <path class="lp-wire" d="M112 92 H142" marker-end="url(#lp-ah)"/>
            <path class="lp-wire" d="M222 92 H262" marker-end="url(#lp-ah)"/>
            <path class="lp-wire" d="M372 92 H402" marker-end="url(#lp-ah)"/>
            <path class="lp-wire" d="M512 92 H552" marker-end="url(#lp-ah)"/>
            <path class="lp-wire" d="M662 92 H742" marker-end="url(#lp-ah)"/>
            <path class="lp-fb" d="M700 92 V214 H512" marker-end="url(#lp-ahb)"/>
            <path class="lp-fb" d="M402 214 H182 V124" marker-end="url(#lp-ahb)"/>
            <path class="lp-dist" d="M607 34 V58" marker-end="url(#lp-ahr)"/>
            <text class="lp-lbl" x="226" y="84">error</text>
            <text class="lp-lbl" x="672" y="84">output</text>
            <text class="lp-lbl" x="560" y="232">measured</text>
            <text class="lp-lbl" x="190" y="160">feedback</text>
            <g class="lp-box" data-k="set" tabindex="0" role="button"><rect x="8" y="70" width="104" height="44" rx="7"/><text x="60" y="96" text-anchor="middle">Set point</text></g>
            <g class="lp-box" data-k="comp" tabindex="0" role="button"><rect x="142" y="62" width="80" height="60" rx="30"/><text x="182" y="88" text-anchor="middle">Compare</text><text x="182" y="104" text-anchor="middle" style="font-size:10px;fill:var(--t-ink-3)">goal − actual</text></g>
            <g class="lp-box" data-k="ctrl" tabindex="0" role="button"><rect x="262" y="70" width="110" height="44" rx="7"/><text x="317" y="96" text-anchor="middle">Controller</text></g>
            <g class="lp-box" data-k="act" tabindex="0" role="button"><rect x="402" y="70" width="110" height="44" rx="7"/><text x="457" y="96" text-anchor="middle">Actuator</text></g>
            <g class="lp-box" data-k="proc" tabindex="0" role="button"><rect x="552" y="62" width="110" height="60" rx="7"/><text x="607" y="96" text-anchor="middle">Process</text></g>
            <g class="lp-box" data-k="sens" tabindex="0" role="button"><rect x="402" y="192" width="110" height="44" rx="7"/><text x="457" y="218" text-anchor="middle">Sensor</text></g>
            <g class="lp-box" data-k="dist" tabindex="0" role="button"><rect x="555" y="6" width="104" height="26" rx="6"/><text x="607" y="23" text-anchor="middle" style="fill:var(--t-bad)">Disturbance</text></g>
        </svg></div>
        <div class="map">
            <div class="map-def" data-def aria-live="polite"></div>
            <div style="min-width:0"><table class="map-tbl" data-tbl></table></div>
        </div>
    </div>
</figure>
