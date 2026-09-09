<?php

declare(strict_types=1);

namespace OCA\SharedMail\Controller;

use OCA\SharedMail\AppInfo\Application;
use OCA\SharedMail\Service\ContactSearchService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Throwable;

class ContactController extends Controller
{
    public function __construct(
        IRequest $request,
        private readonly ContactSearchService $contactSearchService,
    ) {
        parent::__construct(
            Application::APP_ID,
            $request
        );
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function search(
        string $query = '',
    ): JSONResponse {
        try {
            $query =
                trim(
                    $query
                );

            if (
                mb_strlen(
                    $query
                ) < 2
            ) {
                return new JSONResponse([
                    'success' =>
                        true,

                    'contacts' =>
                        [],
                ]);
            }

            $contacts =
                $this
                    ->contactSearchService
                    ->search(
                        $query
                    );

            return new JSONResponse([
                'success' =>
                    true,

                'contacts' =>
                    $contacts,
            ]);
        } catch (Throwable) {
            /*
             * Adressbuch darf niemals den
             * Mail-Composer unbrauchbar machen.
             */
            return new JSONResponse([
                'success' =>
                    true,

                'contacts' =>
                    [],
            ]);
        }
    }
}