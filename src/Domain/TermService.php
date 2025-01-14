<?php

namespace App\Domain;

use App\Entity\Term;
use App\Entity\Language;
use App\Repository\LanguageRepository;
use App\Entity\Status;
use App\DTO\TermReferenceDTO;
use App\Utils\Connection;
use App\Repository\TermRepository;

class TermService {

    private TermRepository $term_repo;
    private array $pendingTerms;

    public function __construct(
        TermRepository $term_repo
    ) {
        $this->term_repo = $term_repo;
        $this->pendingTerms = array();
    }

    public function add(Term $term, bool $flush = true) {
        $this->pendingTerms[] = $term;
        $this->term_repo->save($term, false);
        if ($flush) {
            $this->flush();
        }
    }

    public function flush() {
        /* * /
        $msg = 'flushing ' . count($this->pendingTerms) . ' terms: ';
        foreach ($this->pendingTerms as $t) {
            $msg .= $t->getText();
            if ($t->getParent() != null)
                $msg .= " (parent " . $t->getParent()->getText() . ")";
            $msg .= ', ';
        }
        // dump($msg);
        /* */
        $this->term_repo->flush();
        $this->pendingTerms = array();
    }

    public function remove(Term $term): void
    {
        $this->term_repo->remove($term, false);
        $this->term_repo->flush();
    }

    /** Force delete of FlashMessage.
     *
     * I couldn't get the messages to actually get removed from the db
     * without directly hitting the db like this (see the comments in
     * ReadingFacade) ... I suspect something is getting cached but
     * can't sort it out.  This works fine, so it will do for now!
    */
    public function killFlashMessageFor(Term $term): void {
        $conn = Connection::getFromEnvironment();
        $sql = 'delete from wordflashmessages where WfWoID = ' . $term->getID();
        $conn->query($sql);
    }

    /**
     * Find a term by an exact match.
     */
    public function find(string $value, Language $lang): ?Term {
        $spec = new Term($lang, $value);
        return $this->term_repo->findBySpecification($spec);
    }

    /**
     * Find Terms by matching text.
     */
    public function findMatches(string $value, Language $lang, int $maxResults = 50): array
    {
        $spec = new Term($lang, $value);
        return $this->term_repo->findLikeSpecification($spec, $maxResults);
    }

    /**
     * Find references.
     */
    public function findReferences(Term $term): array
    {
        $conn = Connection::getFromEnvironment();
        $p = $term->getParent();
        $ret = [
            'term' => $this->getReferences($term, $conn),
            'parent' => $this->getReferences($p, $conn),
            'children' => $this->getChildReferences($term, $conn),
            'siblings' => $this->getSiblingReferences($p, $term, $conn)
        ];
        return $ret;
    }

    private function buildTermReferenceDTOs($termlc, $res) {
        $ret = [];
        $zws = mb_chr(0x200B); // zero-width space.
        while (($row = $res->fetch(\PDO::FETCH_ASSOC))) {
            $s = $row['SeText'];
            $s = trim($s);

            $pattern = "/{$zws}({$termlc}){$zws}/ui";
            $replacement = "{$zws}<b>" . '${1}' . "</b>{$zws}";
            $s = preg_replace($pattern, $replacement, $s);

            $ret[] = new TermReferenceDTO($row['TxID'], $row['TxTitle'], $s);
        }
        return $ret;
    }

    private function getReferences($term, $conn): array {
        if ($term == null)
            return [];
        $s = $term->getTextLC();
        $sql = "select distinct TxID, TxTitle, SeText
          from sentences
          inner join texts on TxID = SeTxID
          WHERE TxReadDate is not null
          AND lower(SeText) like '%' || char(0x200B) || ? || char(0x200B) || '%'
          LIMIT 20";
        $stmt = $conn->prepare($sql);

        // TODO:sqlite uses SQLITE3_TEXT
        $stmt->bindValue(1, $s, \PDO::PARAM_STR);

        if (!$stmt->execute()) {
            throw new \Exception($stmt->error);
        }
        return $this->buildTermReferenceDTOs($s, $stmt);
    }

    private function getAllRefs($terms, $conn): array {
        $ret = [];
        foreach ($terms as $term) {
            $ret[] = $this->getReferences($term, $conn);
        }
        return array_merge([], ...$ret);
    }

    private function getSiblingReferences($parent, $term, $conn): array {
        if ($term == null || $parent == null)
            return [];
        $sibs = [];
        foreach ($parent->getChildren() as $s)
            $sibs[] = $s;
        $sibs = array_filter($sibs, fn($t) => $t->getID() != $term->getID());
        return $this->getAllRefs($sibs, $conn);
    }

    private function getChildReferences($term, $conn): array {
        if ($term == null)
            return [];
        return $this->getAllRefs($term->getChildren(), $conn);
    }

}