<?php

declare(strict_types=1);

namespace App\Controller;

use App\View\CabinetsBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CabinetController extends AbstractController
{
    #[Route('/armoires', name: 'app_cabinets', methods: ['GET'])]
    public function __invoke(CabinetsBuilder $builder): Response
    {
        return $this->render('cabinets/index.html.twig', $builder->build());
    }
}
