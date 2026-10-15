<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Import Limits
    |--------------------------------------------------------------------------
    |
    | The ceilings the import endpoint enforces, for the same reason
    | config/images.php holds its numbers: one place the endpoint, the docs and
    | anything generating a client-side hint all read, so the limit a person is
    | told cannot drift from the limit that is checked.
    |
    */

    /*
     * Larger than the image limit, because a document is bigger than the picture
     * that used to be the only upload: a one-page PDF or a small spreadsheet is
     * comfortably past the 512 KB a CSV was capped at. The row cap below is what
     * actually bounds the work that matters, so this number only has to be big
     * enough not to refuse an ordinary report.
     */
    'max_kilobytes' => (int) env('IMPORTS_MAX_KILOBYTES', 5120),

    /*
     * A row cap as well as a size cap, because the two bound different work.
     *
     * The kilobyte limit bounds the extraction — unzipping a document and reading
     * a page is a second of work. The row cap bounds the writes, which happen
     * after extraction has already succeeded and cost something per row: each row
     * creates a sale, its item and its history entry. It is also the ceiling on
     * what the model is allowed to hand back, so a document that provokes a long
     * answer cannot become twenty thousand sales in one submission.
     */

    'max_rows' => (int) env('IMPORTS_MAX_ROWS', 200),

    /*
     * The ceiling on the text sent to the model, in characters.
     *
     * This is a cost and latency guard rather than a data limit: the model is
     * billed per token and answers within one request, so a document long enough
     * to exceed this is refused by name instead of truncated. Truncating would
     * quietly drop rows from the middle of a report, and a draft that is missing
     * rows without saying so is worse than one that was never made.
     */

    'text_limit' => (int) env('IMPORTS_TEXT_LIMIT', 20000),

    /*
    |--------------------------------------------------------------------------
    | Upload Rules
    |--------------------------------------------------------------------------
    |
    | Which byte types an import file may be.
    |
    | The list is deliberately loose, and the looseness is safe here in a way it
    | would not be for an upload that is served back to a browser: this file is
    | never served anywhere. It is decoded, split into rows and turned into sales,
    | so there is no path by which its bytes are executed or rendered.
    |
    | What actually rejects a file is ReadSalesDocument, which opens the bytes and
    | recognises a plain-text report, a ZIP-based Office document or a PDF by its
    | structure. A list of MIME types cannot do that job: the same spreadsheet
    | arrives as application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
    | or as application/zip depending on the browser that picked it, and a .docx is
    | a ZIP before it is anything else. Refusing those over a type that carries no
    | authority here would reject correct files for a cosmetic reason, so the list
    | stays wide and the inspection stays the gate.
    |
    | The CSV types are on the list for the same reason, and they are the clearest
    | case of it: libmagic decides a text file is text/csv from its content, so
    | every comma-separated .txt this application is meant to read arrives under
    | that type. Dropping CSV as a supported format removed the sample files and
    | the documentation, not the bytes — a delimited text file is a table whatever
    | the client called it, and the inspection here is what decides to read it as
    | one.
    |
    */

    'mimetypes' => [
        'text/plain',
        'text/csv',
        'application/csv',
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
        'application/octet-stream',
    ],

    /*
    |--------------------------------------------------------------------------
    | The File Contract
    |--------------------------------------------------------------------------
    |
    | The columns a tabular import must have, and nothing else.
    |
    | Fixed, rather than a mapping this application invents per file, because the
    | alternative is guessing: a column called `total` could be a line total, an
    | order total or an average, and a wrong guess is not a visible failure but a
    | plausible-looking invoice. A declared contract makes the file's obligation
    | explicit, and makes a mismatch a message rather than a silent re-interpretation.
    |
    | The contract applies to the tabular path: a .txt or .xlsx whose first row the
    | columns below describe is read row by row with no model involved. A source
    | that is not tabular — a Word document, a PDF, a free-form text report — is
    | handed to the model instead, and its draft is shown for a person to check
    | before anything is written. Both paths end in the same validation, so a name
    | the catalogue does not carry is refused either way.
    |
    | Order does not matter — the columns are matched by name — but the set does,
    | both ways. An unknown column is refused rather than ignored, because a header
    | that was meant to be read and was not is a file the person believed was
    | imported when it was only partly so.
    |
    | What is absent is as much of the contract as what is present: a customer, a
    | date and a status are per-order facts the file does not carry, so they are
    | asked for on the form instead. Every row therefore becomes its own single-item
    | sale; grouping rows into one order is what the manual sale form is for.
    |
    */

    'columns' => [
        'sales',
        'kota',
        'produk',
        'jumlah',
        'harga',
    ],

    /*
     * Comma first because that is what a delimited text file is, and semicolon
     * because a decimal comma is a normal way to write rupiah amounts, which makes
     * the comma the delimiter in files exported from Indonesian locales. The text
     * is tried against each in turn and whichever yields the contract header wins;
     * there is no configuration to get wrong. An .xlsx needs none of this: its
     * cells arrive already separated.
     */

    'delimiters' => [',', ';'],

    /*
     * Mirrors StoreSaleRequest's line rules rather than inventing import-specific
     * ones, so a quantity or price that the manual form would refuse is not
     * accepted here through the back door. A price of zero is allowed for the same
     * reason it is there: a complimentary or sample line is a real thing to file.
     */

    'max_quantity' => 10000,

];
