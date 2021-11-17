<?php declare(strict_types=1);

namespace App\Entity;

use App\Entity\Contracts\ContentInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity()
 */
class EntryCardanoPaymentInit extends CardanoPaymentInit
{
    /**
     * @ORM\ManyToOne(targetEntity="Entry")
     */
    public ?Entry $entry;

    public function __construct(ContentInterface $entry, ?User $user = null)
    {
        parent::__construct($entry->magazine, $user);

        $this->entry = $entry;
    }

    public function getSubject(): Entry
    {
        return $this->entry;
    }

    public function clearSubject(): EntryCardanoPaymentInit
    {
        $this->entry = null;

        return $this;
    }

    public function getType(): string
    {
        return 'entry';
    }
}