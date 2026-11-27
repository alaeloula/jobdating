<?php

use App\Http\Middleware\EnsureSessionIsValid;
use App\Http\Middleware\NoStore;
use App\Services\Cv\CvService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum en mode session par cookie pour l'application React (CDC 9.5).
        $middleware->statefulApi();

        // CNIE (décision 9 du 19/11/2026) : seuls les espaces sont retirés, par App\Support\Cnie ;
        // un retour à la ligne ou une tabulation est REFUSÉ, jamais supprimé en silence (revue T-12, constat 5).
        $middleware->trimStrings(except: ['cnie']);

        // Backend en API seule : un visiteur non connecté reçoit une erreur 401, jamais une redirection.
        $middleware->redirectGuestsTo(fn (Request $request) => null);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'session.valid' => EnsureSessionIsValid::class,
            'no.store' => NoStore::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Mots de passe et jetons jamais conservés ni renvoyés après une erreur (T-05).
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'token']);

        // Erreurs de l'API toujours en JSON, en français, sans détail technique (EXI-SEC-05).
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (AuthenticationException $e, Request $request) => response()->json([
            'message' => 'Votre session a expiré. Veuillez vous reconnecter.',
            'code' => 'unauthenticated',
        ], 401));

        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException|UnauthorizedException $e, Request $request) {
            return response()->json([
                'message' => 'Vous n\'avez pas accès à cette page.',
                'code' => 'forbidden',
            ], 403);
        });

        // Laravel transforme TokenMismatchException en HttpException 419 avant ces callbacks :
        // on intercepte donc l'exception HTTP, uniquement pour le statut 419.
        $exceptions->render(fn (HttpException $e, Request $request) => $e->getStatusCode() === 419
            ? response()->json([
                'message' => 'La page a expiré. Veuillez réessayer.',
                'code' => 'csrf_token_mismatch',
            ], 419)
            : null);

        // Corps de requête au-delà de `post_max_size` (plan T-20 v1.2, § 4, cas 3) : message du CV pour
        // l'envoi d'un CV SEULEMENT (méthode et chemin : la route peut ne pas être résolue) ; les autres
        // routes (imports, logos) gardent la réponse 413 par défaut.
        $exceptions->render(fn (PostTooLargeException $e, Request $request) => $request->isMethod('POST')
            && preg_match('#^api/v1/learners/\d+/cv$#', $request->path()) === 1
            ? response()->json([
                'message' => CvService::INVALID_MESSAGE,
                'errors' => ['file' => [CvService::INVALID_MESSAGE]],
            ], 422)
            : null);

        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => 'Ressource introuvable.', 'code' => 'not_found'], 404)
            : null);
    })->create();
