<?php

namespace Tests\Feature;

use App\Http\Middleware\RedirectRootToDashboard;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * La racine mène au tableau de bord. Remplace le ExampleTest de Laravel, qui affirmait que `/`
 * répondait 200 - ce qui est précisément ce qui a changé.
 */
class RootRedirectTest extends TestCase
{
    public function test_the_root_redirects_to_the_dashboard(): void
    {
        $this->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_the_redirection_opens_nothing_to_a_visitor(): void
    {
        // La protection reste celle du tableau de bord : renvoyé vers la page de connexion.
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    /**
     * Le cas qui n'apparaît que sous un sous-chemin, et seulement avec le cache de routes :
     * `/metre/` n'est pas routable (voir RedirectRootToDashboard), donc c'est le middleware -
     * exécuté avant le routage - qui doit répondre. Le client de test ne peut pas fabriquer un
     * chemin de base, alors la requête est construite à la main comme nginx la présente à PHP.
     */
    public function test_the_middleware_answers_the_root_of_a_sub_path_installation(): void
    {
        $request = Request::create('/metre/', 'GET', [], [], [], [
            'REQUEST_URI' => '/metre/',
            'SCRIPT_NAME' => '/metre/index.php',
            'PHP_SELF' => '/metre/index.php',
            'SCRIPT_FILENAME' => base_path('public/index.php'),
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'shakemetre.example.test',
        ]);

        $this->assertSame('/metre', $request->getBaseUrl(), 'la requête doit bien porter un chemin de base');
        $this->assertSame('/', $request->getPathInfo());

        $response = (new RedirectRootToDashboard)->handle($request, fn () => response('routé'));

        $this->assertTrue($response->isRedirect(), 'la racine d’un sous-chemin doit être redirigée');
        $this->assertStringEndsWith('/dashboard', (string) $response->headers->get('Location'));
    }

    public function test_every_other_path_passes_through_untouched(): void
    {
        $response = (new RedirectRootToDashboard)->handle(
            Request::create('/references', 'GET'),
            fn () => response('routé')
        );

        $this->assertSame('routé', $response->getContent());
    }
}
