<?php

it('sends the site root to the comp builder', function () {
    // '/' used to render WowComps directly; since 2026-09-16 every game-scoped page lives under
    // /wow and the root permanently redirects there. See GameScopedUrlsTest for the full contract.
    $this->get('/')->assertStatus(301)->assertRedirect('/wow/comps');
});
