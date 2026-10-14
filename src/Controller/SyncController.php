<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SyncRun;
use App\Message\SyncCommvault;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class SyncController extends AbstractController
{
    /**
     * "Synchroniser maintenant": queued for the worker (or run inline when
     * MESSENGER_TRANSPORT_DSN=sync://, e.g. the local preview container).
     */
    #[Route('/synchroniser', name: 'app_sync_now', methods: ['POST'])]
    public function now(Request $request, MessageBusInterface $bus): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('sync-now', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide, recommencez.');
        } else {
            $bus->dispatch(new SyncCommvault('manual'));
            $this->addFlash('success', 'Synchronisation demandée. Les écrans se mettent à jour dès qu\'elle est terminée.');
        }

        $back = (string) $request->headers->get('referer', '');

        return $this->redirect(str_starts_with($back, $request->getSchemeAndHttpHost()) ? $back : $this->generateUrl('app_dashboard'));
    }

    /** Last runs, for the header badge and its history. */
    #[Route('/synchronisations', name: 'app_sync_history', methods: ['GET'])]
    public function history(EntityManagerInterface $em): Response
    {
        return $this->render('sync/history.html.twig', [
            'runs' => $em->getRepository(SyncRun::class)->findBy([], ['startedAt' => 'DESC', 'id' => 'DESC'], 50),
        ]);
    }
}
