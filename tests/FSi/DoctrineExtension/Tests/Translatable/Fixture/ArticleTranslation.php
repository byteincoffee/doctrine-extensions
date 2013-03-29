<?php

namespace FSi\DoctrineExtension\Tests\Translatable\Fixture;

use Doctrine\ORM\Mapping as ORM;
use FSi\DoctrineExtension\Translatable\Mapping\Annotation as Translatable;

/**
 * @ORM\Entity
 */
class ArticleTranslation
{
    /**
     * @ORM\Column(name="id", type="bigint")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     * @var integer $id
     */
    private $id;

    /**
     * @Translatable\Language
     * @ORM\Column(type="string", length=2)
     * @var string
     */
    private $language;

    /**
     * @ORM\Column
     * @var string
     */
    private $title;

    /**
     * @ORM\Column
     * @var string
     */
    private $contents;

    /**
     * @ORM\ManyToOne(targetEntity="Article", inversedBy="translations")
     * @ORM\JoinColumn(name="article", referencedColumnName="id")
     * @var Doctrine\Common\Collections\ArrayCollection
     */
    private $article;

    public function setTitle($title)
    {
        $this->title = (string)$title;
        return $this;
    }

    public function getTitle()
    {
        return $this->title;
    }

    public function setContents($contents)
    {
        $this->contents = (string)$contents;
        return $this;
    }

    public function getContents()
    {
        return $this->contents;
    }

    public function setLanguage($language)
    {
        $this->language = (string)$language;
        return $this;
    }

    public function getLanguage()
    {
        return $this->language;
    }

}
