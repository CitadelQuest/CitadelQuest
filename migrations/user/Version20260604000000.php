<?php

/**
 * Migration: Simplify fileUpdate AI Tool content/description guidance.
 *
 * The trailing-newline handling is now resolved server-side, so the schema no
 * longer needs to warn Spirits about it. This:
 * - Trims the `content` parameter description down to its essential meaning.
 * - Removes the "For content fields, do NOT add a trailing newline ..." sentence
 *   from the tool description.
 *
 * Only the fileUpdate tool is touched. Same approach as Version20260602000000.
 */
class UserMigration_20260604000000
{
    public function up(\PDO $db): void
    {
        $this->updateFileUpdate(
            $db,
            [
                'type' => 'object',
                'properties' => [
                    'projectId' => [
                        'type' => 'string',
                        'description' => 'Project ID'
                    ],
                    'pathname' => [
                        'type' => 'string',
                        'description' => 'Full path including filename of the file to update, e.g. "/spirit/Amidamaru/memory/notes.md".'
                    ],
                    'operation' => [
                        'type' => 'string',
                        'enum' => ['replace', 'lineRange', 'append', 'prepend', 'insertAtLine'],
                        'description' => 'Update operation type. Pick ONE per call. replace = literal find/replace (best for small unique snippets). lineRange = swap a range of lines for new content. insertAtLine = inject content before a given line. append/prepend = add to end/start of file.'
                    ],
                    'find' => [
                        'type' => 'string',
                        'description' => 'Text to find (for replace operation). Must match exactly, including whitespace and newlines.'
                    ],
                    'replaceWith' => [
                        'type' => 'string',
                        'description' => 'Text to replace `find` with (for replace operation). Use empty string to delete the matched text.'
                    ],
                    'startLine' => [
                        'type' => 'integer',
                        'description' => 'Start line number for lineRange (1-based, inclusive). Line is read with fileManage read + withLineNumbers=true.'
                    ],
                    'endLine' => [
                        'type' => 'integer',
                        'description' => 'End line number for lineRange (1-based, inclusive). Both startLine and endLine are part of the replaced range. To replace a single line use startLine == endLine.'
                    ],
                    'line' => [
                        'type' => 'integer',
                        'description' => 'Line number to insert at for insertAtLine (1-based). Content is inserted BEFORE this line, pushing the existing line down.'
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'Content for lineRange, insertAtLine, append, or prepend.'
                    ]
                ],
                'required' => ['projectId', 'pathname', 'operation']
            ],
            'Update a file in place. One operation per call. ' .
                'Identify the file with `pathname` (full path including filename, e.g. "/dir/sub/file.md"). ' .
                'Operations: ' .
                'replace (find + replaceWith — best for small, uniquely-identifiable snippets); ' .
                'lineRange (startLine + endLine + content, both bounds inclusive — best after fileManage read withLineNumbers=true); ' .
                'insertAtLine (line + content — inserts BEFORE the given line); ' .
                'append / prepend (content — adds to end/start of file). ' .
                'For multiple edits in one file, call this tool multiple times.'
        );
    }

    public function down(\PDO $db): void
    {
        // Restore fileUpdate to Version20260602000000 (with trailing-newline guidance).
        $this->updateFileUpdate(
            $db,
            [
                'type' => 'object',
                'properties' => [
                    'projectId' => [
                        'type' => 'string',
                        'description' => 'Project ID'
                    ],
                    'pathname' => [
                        'type' => 'string',
                        'description' => 'Full path including filename of the file to update, e.g. "/spirit/Amidamaru/memory/notes.md".'
                    ],
                    'operation' => [
                        'type' => 'string',
                        'enum' => ['replace', 'lineRange', 'append', 'prepend', 'insertAtLine'],
                        'description' => 'Update operation type. Pick ONE per call. replace = literal find/replace (best for small unique snippets). lineRange = swap a range of lines for new content. insertAtLine = inject content before a given line. append/prepend = add to end/start of file.'
                    ],
                    'find' => [
                        'type' => 'string',
                        'description' => 'Text to find (for replace operation). Must match exactly, including whitespace and newlines.'
                    ],
                    'replaceWith' => [
                        'type' => 'string',
                        'description' => 'Text to replace `find` with (for replace operation). Use empty string to delete the matched text.'
                    ],
                    'startLine' => [
                        'type' => 'integer',
                        'description' => 'Start line number for lineRange (1-based, inclusive). Line is read with fileManage read + withLineNumbers=true.'
                    ],
                    'endLine' => [
                        'type' => 'integer',
                        'description' => 'End line number for lineRange (1-based, inclusive). Both startLine and endLine are part of the replaced range. To replace a single line use startLine == endLine.'
                    ],
                    'line' => [
                        'type' => 'integer',
                        'description' => 'Line number to insert at for insertAtLine (1-based). Content is inserted BEFORE this line, pushing the existing line down.'
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'Content for lineRange, insertAtLine, append, or prepend. IMPORTANT: do NOT end with a trailing "\\n" — line separation is handled automatically and a trailing newline would create an unintended blank line (one trailing "\\n" is auto-stripped; use "\\n\\n" if you intentionally want a blank line at the end).'
                    ]
                ],
                'required' => ['projectId', 'pathname', 'operation']
            ],
            'Update a file in place. One operation per call. ' .
                'Identify the file with `pathname` (full path including filename, e.g. "/dir/sub/file.md"). ' .
                'Operations: ' .
                'replace (find + replaceWith — best for small, uniquely-identifiable snippets); ' .
                'lineRange (startLine + endLine + content, both bounds inclusive — best after fileManage read withLineNumbers=true); ' .
                'insertAtLine (line + content — inserts BEFORE the given line); ' .
                'append / prepend (content — adds to end/start of file). ' .
                'For content fields, do NOT add a trailing newline (it is auto-stripped to avoid phantom blank lines; use "\\n\\n" for an intentional trailing blank). ' .
                'For multiple edits in one file, call this tool multiple times.'
        );
    }

    private function updateFileUpdate(\PDO $db, array $params, string $description): void
    {
        $stmt = $db->prepare("UPDATE ai_tool SET parameters = ?, description = ?, updated_at = ? WHERE name = ?");
        $stmt->execute([
            json_encode($params),
            $description,
            date('Y-m-d H:i:s'),
            'fileUpdate',
        ]);
    }
}
