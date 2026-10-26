<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

/**
 * An expired sign-in form (26 Oct 2026): a second press of "Sign in" a second after the first had
 * already signed in showed "419 Page Expired" over a sign-in that had worked. CSRF is not checked in
 * tests, so the mismatch is rendered the way the app renders it (bootstrap/app.php).
 */
class ExpiredSignInFormTest extends TestCase
{
    use RefreshDatabase;

    private function render(string $uri, ?User $user = null)
    {
        $request = Request::create($uri, 'POST', ['email' => 'a@b.test', 'password' => 'secret']);
        $request->setLaravelSession($this->app['session.store']);
        $request->setRouteResolver(fn () => $this->app['router']->getRoutes()->match($request));
        if ($user) {
            $this->actingAs($user);
        }
        $this->app->instance('request', $request);

        return $this->app[ExceptionHandler::class]->render($request, new TokenMismatchException('CSRF token mismatch.'));
    }

    public function test_signed_in_already_goes_on_instead_of_page_expired(): void
    {
        $response = $this->render('/login', User::factory()->create());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/dashboard', $response->headers->get('Location'));
    }

    public function test_a_guest_gets_the_form_back_with_a_note_and_no_password(): void
    {
        $response = $this->render('/login');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringEndsWith('/login', $response->headers->get('Location'));
        $this->assertSame('a@b.test', session()->getOldInput('email'));
        $this->assertNull(session()->getOldInput('password'));
        $this->assertStringContainsString('needed a refresh', session('errors')->first('email'));
    }

    public function test_other_pages_keep_the_usual_419(): void
    {
        $this->assertSame(419, $this->render('/logout')->getStatusCode());
    }
}
