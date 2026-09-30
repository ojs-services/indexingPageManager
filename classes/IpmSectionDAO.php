<?php
/**
 * @file classes/IpmSectionDAO.php
 *
 * Indexing Page Manager — Section DAO.
 *
 * Built-in sections are seeded once per journal at plugin enable time and
 * their slugs are immutable. Display names are multilingual and stored in the
 * ipm_section_settings table.
 */

namespace APP\plugins\generic\indexingPageManager\classes;

use PKP\core\Core;
use PKP\db\DAO;

class IpmSectionDAO extends DAO
{
    public function newDataObject()
    {
        return new IpmSection();
    }

    public function getById($sectionId, $journalId = null)
    {
        $sql = 'SELECT * FROM ipm_sections WHERE section_id = ?';
        $params = [(int) $sectionId];
        if ($journalId !== null) {
            $sql .= ' AND journal_id = ?';
            $params[] = (int) $journalId;
        }
        $row = $this->retrieve($sql, $params)->current();
        return $row ? $this->_fromRow((array) $row) : null;
    }

    public function getBySlug($slug, $journalId)
    {
        $row = $this->retrieve(
            'SELECT * FROM ipm_sections WHERE slug = ? AND journal_id = ?',
            [(string) $slug, (int) $journalId]
        )->current();
        return $row ? $this->_fromRow((array) $row) : null;
    }

    /**
     * @return IpmSection[]
     */
    public function getByJournalId($journalId, $activeOnly = false)
    {
        $sql = 'SELECT * FROM ipm_sections WHERE journal_id = ?';
        $params = [(int) $journalId];
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY seq, section_id';

        $sections = [];
        foreach ($this->retrieve($sql, $params) as $row) {
            $sections[] = $this->_fromRow((array) $row);
        }
        return $sections;
    }

    public function getMaxSeq($journalId)
    {
        $row = $this->retrieve(
            'SELECT MAX(seq) AS max_seq FROM ipm_sections WHERE journal_id = ?',
            [(int) $journalId]
        )->current();
        return $row ? (int) $row->max_seq : 0;
    }

    public function insertObject($section)
    {
        $createdAt = $section->getCreatedAt() ?: Core::getCurrentDate();
        $section->setCreatedAt($createdAt);

        $this->update(
            'INSERT INTO ipm_sections
              (journal_id, slug, is_built_in, is_active, seq, created_at)
              VALUES (?, ?, ?, ?, ?, ?)',
            [
                (int) $section->getJournalId(),
                (string) $section->getSlug(),
                $section->getIsBuiltIn() ? 1 : 0,
                $section->getIsActive() ? 1 : 0,
                $section->getSeq(),
                $createdAt,
            ]
        );

        $section->setId($this->getInsertId());
        $this->updateLocaleFields($section);
        return $section->getId();
    }

    public function updateObject($section)
    {
        $this->update(
            'UPDATE ipm_sections
              SET slug = ?, is_built_in = ?, is_active = ?, seq = ?
              WHERE section_id = ?',
            [
                (string) $section->getSlug(),
                $section->getIsBuiltIn() ? 1 : 0,
                $section->getIsActive() ? 1 : 0,
                $section->getSeq(),
                (int) $section->getId(),
            ]
        );
        $this->updateLocaleFields($section);
    }

    /**
     * Built-in sections cannot be deleted; caller must check
     * $section->getIsBuiltIn() first.
     */
    public function deleteObject($section)
    {
        return $this->deleteById((int) $section->getId());
    }

    public function deleteById($sectionId)
    {
        $this->update('DELETE FROM ipm_section_settings WHERE section_id = ?', [(int) $sectionId]);
        $this->update('DELETE FROM ipm_index_section WHERE section_id = ?', [(int) $sectionId]);
        return $this->update('DELETE FROM ipm_sections WHERE section_id = ?', [(int) $sectionId]);
    }

    public function getInsertId(): int
    {
        return parent::getInsertId();
    }

    public function getLocaleFieldNames(): array
    {
        return ['displayName'];
    }

    public function updateLocaleFields($section)
    {
        $this->updateDataObjectSettings(
            'ipm_section_settings',
            $section,
            ['section_id' => (int) $section->getId()]
        );
    }

    private function _fromRow($row)
    {
        $section = $this->newDataObject();
        $section->setId((int) $row['section_id']);
        $section->setJournalId((int) $row['journal_id']);
        $section->setSlug($row['slug']);
        $section->setIsBuiltIn((bool) $row['is_built_in']);
        $section->setIsActive((bool) $row['is_active']);
        $section->setSeq((int) $row['seq']);
        $section->setCreatedAt($row['created_at']);

        $this->getDataObjectSettings(
            'ipm_section_settings',
            'section_id',
            (int) $row['section_id'],
            $section
        );
        return $section;
    }
}
