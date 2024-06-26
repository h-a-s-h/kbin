<?php

// SPDX-FileCopyrightText: 2023 /kbin contributors <https://kbin.pub/>
//
// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Contracts\ActivityPubActivityInterface;
use App\Entity\Contracts\CommentInterface;
use App\Entity\Contracts\FavouriteInterface;
use App\Entity\Contracts\RankingInterface;
use App\Entity\Contracts\ReportInterface;
use App\Entity\Contracts\TagInterface;
use App\Entity\Contracts\VisibilityInterface;
use App\Entity\Contracts\VotableInterface;
use App\Entity\Traits\ActivityPubActivityTrait;
use App\Entity\Traits\CreatedAtTrait;
use App\Entity\Traits\EditedAtTrait;
use App\Entity\Traits\RankingTrait;
use App\Entity\Traits\VisibilityTrait;
use App\Entity\Traits\VotableTrait;
use App\Repository\PostRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Webmozart\Assert\Assert;

#[Entity(repositoryClass: PostRepository::class)]
#[Index(columns: ['visibility', 'is_adult'], name: 'post_visibility_adult_idx')]
#[Index(columns: ['visibility'], name: 'post_visibility_idx')]
#[Index(columns: ['is_adult'], name: 'post_adult_idx')]
#[Index(columns: ['ranking'], name: 'post_ranking_idx')]
#[Index(columns: ['score'], name: 'post_score_idx')]
#[Index(columns: ['comment_count'], name: 'post_comment_count_idx')]
#[Index(columns: ['created_at'], name: 'post_created_at_idx')]
#[Index(columns: ['last_active'], name: 'post_last_active_at_idx')]
#[Index(columns: ['body_ts'], name: 'post_body_ts_idx')]
#[Index(columns: ['tags'], name: 'post_tags_idx')]
#[Index(columns: ['ap_id'], name: 'post_ap_id_idx')]
class Post implements VotableInterface, CommentInterface, VisibilityInterface, RankingInterface, ReportInterface, FavouriteInterface, TagInterface, ActivityPubActivityInterface
{
    use VotableTrait;
    use RankingTrait;
    use VisibilityTrait;
    use ActivityPubActivityTrait;
    use EditedAtTrait;
    use CreatedAtTrait {
        CreatedAtTrait::__construct as createdAtTraitConstruct;
    }

    #[ManyToOne(targetEntity: User::class, inversedBy: 'posts')]
    #[JoinColumn(nullable: false)]
    public User $user;
    #[ManyToOne(targetEntity: Magazine::class, inversedBy: 'posts')]
    #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Magazine $magazine;
    #[ManyToOne(targetEntity: Image::class, cascade: ['persist'])]
    #[JoinColumn]
    public ?Image $image = null;
    #[Column(type: 'string', length: 255, nullable: true)]
    public string $slug;
    #[Column(type: 'text', length: 15000, nullable: true)]
    public ?string $body = null;
    #[Column(type: 'string')]
    public string $lang = 'en';
    #[Column(type: 'integer')]
    public int $commentCount = 0;
    #[Column(type: 'integer', options: ['default' => 0])]
    public int $favouriteCount = 0;
    #[Column(type: 'integer')]
    public int $score = 0;
    #[Column(type: 'boolean')]
    public bool $isAdult = false;
    #[Column(type: 'boolean', options: ['default' => false])]
    public bool $sticky = false;
    #[Column(type: 'datetimetz')]
    public ?\DateTime $lastActive;
    #[Column(type: 'string', nullable: true)]
    public ?string $ip = null;
    #[Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    public ?array $tags = null;
    #[Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    public ?array $mentions = null;
    #[OneToMany(mappedBy: 'post', targetEntity: PostComment::class, orphanRemoval: true)]
    public Collection $comments;
    #[OneToMany(mappedBy: 'post', targetEntity: PostVote::class, cascade: [
        'persist',
        'remove',
    ], fetch: 'EXTRA_LAZY', orphanRemoval: true)]
    public Collection $votes;
    #[OneToMany(mappedBy: 'post', targetEntity: PostReport::class, cascade: ['remove'], fetch: 'EXTRA_LAZY', orphanRemoval: true)]
    public Collection $reports;
    #[OneToMany(mappedBy: 'post', targetEntity: PostFavourite::class, cascade: ['remove'], fetch: 'EXTRA_LAZY', orphanRemoval: true)]
    public Collection $favourites;
    #[OneToMany(mappedBy: 'post', targetEntity: PostCreatedNotification::class, cascade: ['remove'], fetch: 'EXTRA_LAZY', orphanRemoval: true)]
    public Collection $notifications;
    public array $children = [];
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    private int $id;
    #[Column(type: 'text', nullable: true, insertable: false, updatable: false, options: ['default' => 'english'])]
    private $bodyTs;

    public function __construct(
        ?string $body,
        Magazine $magazine,
        User $user,
        bool $isAdult,
        string $ip = null
    ) {
        $this->body = $body;
        $this->magazine = $magazine;
        $this->user = $user;
        $this->isAdult = $isAdult;
        $this->ip = $ip;
        $this->comments = new ArrayCollection();
        $this->votes = new ArrayCollection();
        $this->reports = new ArrayCollection();
        $this->favourites = new ArrayCollection();
        $this->notifications = new ArrayCollection();

        $this->createdAtTraitConstruct();
        $this->lastActive = new \DateTime();
    }

    public function updateLastActive(): void
    {
        $this->comments->get(-1);

        $criteria = Criteria::create()
            ->orderBy(['createdAt' => 'DESC'])
            ->setMaxResults(1);

        $lastComment = $this->comments->matching($criteria)->first();

        if ($lastComment) {
            $this->lastActive = \DateTime::createFromImmutable($lastComment->createdAt);
        } else {
            $this->lastActive = \DateTime::createFromImmutable($this->getCreatedAt());
        }
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getBestComments(User $user = null): Collection
    {
        $criteria = Criteria::create()
            ->orderBy(['upVotes' => 'DESC', 'createdAt' => 'ASC']);

        $comments = $this->comments->matching($criteria);
        $comments = $this->handlePrivateComments($comments, $user);
        $comments = new ArrayCollection($comments->slice(0, 2));

        if (!\count(array_filter($comments->toArray(), fn ($comment) => $comment->countUpVotes() > 0))) {
            return $this->getLastComments();
        }

        $iterator = $comments->getIterator();
        $iterator->uasort(function ($a, $b) {
            return ($a->createdAt < $b->createdAt) ? -1 : 1;
        });

        return new ArrayCollection(iterator_to_array($iterator));
    }

    private function handlePrivateComments(ArrayCollection $comments, ?User $user): ArrayCollection
    {
        return $comments->filter(function (PostComment $val) use ($user) {
            if ($user && VisibilityInterface::VISIBILITY_PRIVATE === $val->getVisibility()) {
                return $user->isFollower($val->user);
            }

            return VisibilityInterface::VISIBILITY_VISIBLE === $val->getVisibility();
        });
    }

    public function getLastComments(User $user = null): Collection
    {
        $criteria = Criteria::create()
            ->orderBy(['createdAt' => 'ASC']);

        $comments = $this->comments->matching($criteria);

        $comments = $this->handlePrivateComments($comments, $user);

        return new ArrayCollection($comments->slice(-2, 2));
    }

    public function softDelete(): void
    {
        $this->visibility = VisibilityInterface::VISIBILITY_SOFT_DELETED;
    }

    public function trash(): void
    {
        $this->visibility = VisibilityInterface::VISIBILITY_TRASHED;
    }

    public function restore(): void
    {
        $this->visibility = VisibilityInterface::VISIBILITY_VISIBLE;
    }

    public function isAuthor(User $user): bool
    {
        return $user === $this->user;
    }

    public function getShortTitle(?int $length = 60): string
    {
        $body = wordwrap($this->body ?? '', $length);
        $body = explode("\n", $body);

        return trim($body[0]).(isset($body[1]) ? '...' : '');
    }

    public function getCommentCount(): int
    {
        return $this->commentCount;
    }

    public function getUniqueCommentCount(): int
    {
        $users = [];
        $count = 0;
        foreach ($this->comments as $comment) {
            if (!\in_array($comment->user, $users)) {
                $users[] = $comment->user;
                ++$count;
            }
        }

        return $count;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function getMagazine(): ?Magazine
    {
        return $this->magazine;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function isFavored(User $user): bool
    {
        $criteria = Criteria::create()
            ->where(Criteria::expr()->eq('user', $user));

        return $this->favourites->matching($criteria)->count() > 0;
    }

    public function isAdult(): bool
    {
        return $this->isAdult || $this->magazine->isAdult;
    }

    public function getTags(): array
    {
        return array_values($this->tags ?? []);
    }

    public function countCommentsNewestThan(\DateTime $time, User $excludedUser): int
    {
        $criteria = Criteria::create()
            ->andWhere(Criteria::expr()->neq('user', $excludedUser))
            ->andWhere(Criteria::expr()->gt('createdAt', \DateTimeImmutable::createFromMutable($time)));

        return $this->comments->matching($criteria)->count();
    }

    public function __sleep()
    {
        return [];
    }
}
