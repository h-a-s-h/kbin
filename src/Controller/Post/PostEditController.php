<?php

declare(strict_types=1);

namespace App\Controller\Post;

use App\Controller\AbstractController;
use App\Entity\Magazine;
use App\Entity\Post;
use App\Form\PostType;
use App\PageView\PostCommentPageView;
use App\Repository\PostCommentRepository;
use App\Service\PostManager;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class PostEditController extends AbstractController
{
    public function __construct(private readonly PostManager $manager)
    {
    }

    #[IsGranted('ROLE_USER')]
    #[IsGranted('edit', subject: 'post')]
    public function __invoke(
        #[MapEntity(mapping: ['magazine_name' => 'name'])]
        Magazine $magazine,
        #[MapEntity(mapping: ['post_id' => 'id'])]
        Post $post,
        Request $request,
        PostCommentRepository $repository
    ): Response {
        $dto = $this->manager->createDto($post);

        $form = $this->createForm(PostType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->isGranted('create_content', $magazine)) {
                throw new AccessDeniedHttpException();
            }

            $post = $this->manager->edit($post, $dto);

            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(
                    [
                        'id' => $post->getId(),
                        'html' => $this->renderView(
                            'components/_ajax.html.twig',
                            [
                                'component' => 'post',
                                'attributes' => [
                                    'post' => $post,
                                    'showMagazineName' => false,
                                ],
                            ]
                        ),
                    ]
                );
            }

            return $this->redirectToPost($post);
        }

        $criteria = new PostCommentPageView($this->getPageNb($request));
        $criteria->post = $post;

        if ($request->isXmlHttpRequest()) {
            return $this->getJsonFormResponse(
                $form,
                'post/_form_post.html.twig',
                ['post' => $post, 'edit' => true]
            );
        }

        return $this->render(
            'post/edit.html.twig',
            [
                'magazine' => $magazine,
                'post' => $post,
                'comments' => $repository->findByCriteria($criteria),
                'form' => $form->createView(),
            ],
            new Response(null, $form->isSubmitted() && !$form->isValid() ? 422 : 200)
        );
    }
}
