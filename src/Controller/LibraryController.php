<?php

declare(strict_types=1);

namespace App\Controller;

use App\View\LibraryViewBuilder;
use App\View\SlotView;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LibraryController extends AbstractController
{
    #[Route('/tl', name: 'app_library', methods: ['GET'])]
    public function __invoke(LibraryViewBuilder $builder): Response
    {
        return $this->render('library/index.html.twig', [
            'libraries' => $builder->build(),
            'legend' => SlotView::LEGEND,
        ]);
    }
}
