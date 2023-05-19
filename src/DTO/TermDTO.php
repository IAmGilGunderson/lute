<?php

namespace App\DTO;

use App\Entity\Language;
use App\Entity\Term;
use App\Domain\TermService;
use App\Repository\TermTagRepository;

class TermDTO
{

    public ?int $id = null;

    public ?Language $language = null;

    public ?string $Text = null;

    public ?int $Status = 1;

    public ?string $Translation = null;

    public ?string $Romanization = null;

    public ?string $Sentence = null;

    public ?int $WordCount = null;

    public array $termTags;

    public ?string $FlashMessage = null;

    public ?string $ParentText = null;

    public ?int $ParentID = null;

    public ?string $CurrentImage = null;

    public function __construct()
    {
        $this->termTags = array();
    }


    /**
     * Convert the given TermDTO to a Term.
     */
    public static function buildTerm(TermDTO $dto, TermService $term_service, TermTagRepository $ttr): Term
    {
        if (is_null($dto->language)) {
            throw new \Exception('Language not set for term dto');
        }
        if (is_null($dto->Text)) {
            throw new \Exception('Text not set for term dto');
        }

        $t = $term_service->find($dto->Text, $dto->language);
        if ($t == null)
            $t = new Term();

        $t->setLanguage($dto->language);
        $t->setText($dto->Text);
        $t->setStatus($dto->Status);
        $t->setTranslation($dto->Translation);
        $t->setRomanization($dto->Romanization);
        $t->setSentence($dto->Sentence);
        $t->setCurrentImage($dto->CurrentImage);

        $termtags = array();
        foreach ($dto->termTags as $s) {
            $termtags[] = $ttr->findOrCreateByText($s);
        }

        if ($dto->ParentText == $dto->Text)
            $dto->ParentText = null;
        $parent = TermDTO::findOrCreateParent($dto, $term_service, $termtags);
        $t->setParent($parent);

        $t->removeAllTermTags();
        foreach ($termtags as $tt) {
            $t->addTermTag($tt);
        }

        return $t;
    }

    private static function findOrCreateParent(TermDTO $dto, TermService $term_service, array $termtags): ?Term
    {
        $pt = $dto->ParentText;
        if ($pt == null || $pt == '')
            return null;

        $p = $term_service->find($pt, $dto->language);

        if ($p !== null) {
            if (($p->getTranslation() ?? '') == '')
                $p->setTranslation($dto->Translation);
            if (($p->getCurrentImage() ?? '') == '')
                $p->setCurrentImage($dto->CurrentImage);
            return $p;
        }

        $p = new Term();
        $p->setLanguage($dto->language);
        $p->setText($pt);
        $p->setStatus($dto->Status);
        $p->setTranslation($dto->Translation);
        $p->setCurrentImage($dto->CurrentImage);
        $p->setSentence($dto->Romanization);
        foreach ($termtags as $tt)
            $p->addTermTag($tt);

        return $p;
    }

}