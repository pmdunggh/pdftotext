<?php
namespace VanXuan\PdfToText;

/*==============================================================================================================

    class CaptureLinesDefinition -
        A shape for capturing text in rectangle areas.

  ==============================================================================================================*/

class CaptureLinesDefinition extends CaptureShapeDefinition
{
    // Column areas
    public $Columns = [];
    // Top and bottom lines
    public $Tops = [];
    public $Bottoms = [];
    // Column names
    private $ColumnNames = [];


    /*--------------------------------------------------------------------------------------------------------------

        CONSTRUCTOR -
        Analyzes the contents of a <columns> XML node, which contains <page> nodes giving a part of the column
        dimensions, and <column> nodes which specify the name of the column and the remaining coordinates,
        such as "left" or "width"

     *-------------------------------------------------------------------------------------------------------------*/
    public function __construct(\SimpleXMLElement $node)
    {
        parent::__construct(self::SHAPE_COLUMN);

        $shape_attributes = $this->GetAttributes($node, ['default' => false]);
        $column_default = ($shape_attributes ['default']) ? $shape_attributes ['default'] : '';

        // Loop through node's children
        /** @var \SimpleXMLElement $child */
        foreach ($node->children() as $child) {
            $tag_name = $child->getName();

            switch (strtolower($tag_name)) {
                // <page> tag
                case    'page' :
                    // Retrieve the specified attributes
                    $page_attributes = CaptureDefinitions::GetNodeAttributes(
                        $child,
                        [
                            'number' => true,
                            'top' => true,
                            'height' => true,
                            'bottom' => false
                        ]
                    );

                    // We have to store the y-coordinate of the first and last lines, to determine until which
                    // position we have to check for column contents.
                    // The "top" and "bottom" attributes of the <page> tag actually determine the top and bottom
                    // y-coordinates where to search for columns. However, we will have to rename the "bottom"
                    // attribute to "column-bottom", in order for it not to be mistaken with actual column rectangle
                    // (only the "height" attribute of the <page> tag gives the height of a line)
                    $page_attributes ['column-top'] = $page_attributes ['top'];
                    $page_attributes ['column-bottom'] = ( double )$page_attributes ['bottom'];
                    unset($page_attributes ['bottom']);

                    // Add this page to the list of applicable pages for this shape
                    $this->ApplicablePages->Add($page_attributes ['number'], $page_attributes);

                    break;

                // <column> tag :
                case    'column' :
                    $column_attributes = CaptureDefinitions::GetNodeAttributes(
                        $child,
                        [
                            'name' => true,
                            'left' => false,
                            'right' => false,
                            'width' => false,
                            'default' => false
                        ]
                    );

                    $column_name = $column_attributes ['name'];

                    // Build the final default value, if any one is specified ; the following special constructs are processed :
                    // - "%c" :
                    //  Replaced by the column name.
                    // - "%n" :
                    //  Replaced by the column index (starting from zero).
                    if (!$column_attributes ['default']) {
                        $column_attributes ['default'] = $column_default;
                    }

                    $substitutes =
                    [
                        '%c' => $column_name,
                        '%n' => count($this->Columns)
                    ];

                    $column_attributes ['default'] = str_replace(
                        array_keys($substitutes),
                        array_values($substitutes),
                        $column_attributes ['default']
                    );

                    // Add the column definition to this object
                    if (!isset($this->Columns [$column_name])) {
                        $this->Columns [$column_attributes ['name']] = $column_attributes;
                        $this->ColumnNames [] = $column_attributes ['name'];
                    } else {
                        error(new CaptureException("Column \"$column_name\" is defined more than once."));
                    }

                    break;

                // Other tag : throw an exception
                default :
                    error(new CaptureException("Invalid tag <$tag_name> found in root tag <rectangle>."));
            }
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

         ExtractAreas -
        Extracts text contents from the document fragments.

     *-------------------------------------------------------------------------------------------------------------*/
    public function ExtractAreas($document_fragments)
    {
        $result = [];

        // Loop through each page of document fragments
        foreach ($document_fragments as $page => $page_contents) {
            $fragments = $page_contents ['fragments'];

            // Ignore this page if not included in the <columns> definition
            if (!isset($this->ApplicablePages->PageMap [$page])) {
                continue;
            }

            // <columns> definition only gives the location of the first line of each column, together
            // with its height.
            // We will build as many new column areas as can fit on one page
            $this_page_areas = $this->Areas [$page];
            $column_areas = [];

            for ($i = 0, $count = count($this_page_areas); $i < $count; $i++) {
                // For now, duplicate the existing column areas - they will represent the 1st line of columns
                $this_page_area = $this_page_areas [$i];
                $new_area = clone ($this_page_area);
                $column_areas [0] [] = $new_area;
                $line_height = $new_area->Height;
                $current_top = $new_area->Top - $line_height;
                $current_line = 0;

                // Then build new column areas for each successive lines
                while ($current_top - $line_height >= 0) {
                    $current_line++;
                    $new_area = clone ($new_area);
                    $new_area->Top -= $line_height;
                    $new_area->Bottom -= $line_height;

                    $column_areas [$current_line]    [] = $new_area;
                    $current_top -= $line_height;
                }
            }

            // Now extract the columns, line per line, from the current page's text fragments
            $found_lines = [];

            foreach ($fragments as $fragment) {
                $this->GetFragmentData($fragment, $text, $left, $top, $right, $bottom);

                // Loop through each line of column areas, built from the above step
                foreach ($column_areas as $line => $column_areas_per_name) {
                    $index = 0;            // Column index

                    // Process each column area
                    /** @var CapturedColumn $column_area */
                    foreach ($column_areas_per_name as $column_area) {
                        // ... but only do something if the current column area is contained in the current fragment
                        /** @noinspection PhpUndefinedMethodInspection */
                        if ($column_area->Contains($left, $top, $right, $bottom)) {
                            // The normal usage will be to capture one-line columns...
                            if (!isset($found_lines [$line] [$column_area->Name])) {
                                $found_lines [$line] [$column_area->Name] =
                                    new CapturedColumn(
                                        $page,
                                        $column_area->Name,
                                        $text,
                                        $left,
                                        $top,
                                        $right,
                                        $bottom,
                                        $this
                                    );
                            } else {
                                // ... but you can also use them to capture multiple lines ; in this case, the "separator" attribute of the <lines> or
                                // <column> tag will be used to separate items
                                $existing_area = $found_lines [$line] [$column_area->Name];

                                $existing_area->Top = max($existing_area->Top, $column_area->Top);
                                $existing_area->Bottom = min($existing_area->Bottom, $column_area->Bottom);
                                $existing_area->Left = min($existing_area->Left, $column_area->Left);
                                $existing_area->Right = max($existing_area->Right, $column_area->Right);
                                $existing_area->Text .= $this->Separator . $text;
                            }
                        }

                        $index++;
                    }
                }
            }

            // A final pass to provide default values for empty columns (usually, column values that are not represented in the PDF file)
            // Also get the surrounding box for the whole line
            $final_lines = [];

            foreach ($found_lines as $line => $columns_line) {
                foreach ($this->ColumnNames as $column_name) {
                    if (!isset($columns_line [$column_name])) {
                        $columns_line [$column_name] =
                            new CapturedColumn($page, $column_name, $this->Columns [$column_name] ['default'], 0, 0, 0, 0, $this);
                    }
                }

                // Get the (left,top) coordinates of the line
                $line_left = $found_lines [$line] [$this->ColumnNames [0]]->Left;
                $line_top = $found_lines [$line] [$this->ColumnNames [0]]->Top;

                // Get the (right,bottom) coordinates - we have to find the last column whose value is not a default value
                // (and therefore, has a non-zero Right coordinate)
                $last = count($this->ColumnNames) - 1;
                $line_right = 0;
                $line_bottom = 0;

                while ($last >= 0 && !$columns_line [$this->ColumnNames [$last]]->Right) {
                    $last--;
                }

                if ($last > 0) {
                    $line_right = $columns_line [$this->ColumnNames [$last]]->Right;
                    $line_bottom = $columns_line [$this->ColumnNames [$last]]->Bottom;
                }

                // Create a CaptureLine entry
                $final_lines [] = new CapturedLine($page, $this->Name, $columns_line, $line_left, $line_top, $line_right, $line_bottom, $this);
            }

            // The result for this page will be a CapturedLines object
            $result [$page] = new  CapturedLines($this->Name, $page, $final_lines);
        }

        // All done, return
        return ($result);
    }


    /*--------------------------------------------------------------------------------------------------------------

         SetPageCount -
        Extracts text contents from the document fragments.

     *-------------------------------------------------------------------------------------------------------------*/
    public function SetPageCount($count)
    {
        parent::SetPageCount($count);

        foreach ($this->ApplicablePages as $page => $applicable) {
            if (!$applicable) {
                continue;
            }

            foreach ($this->Columns as $column) {
                if (!isset($this->Tops [$page])) {
                    $this->Tops    [$page] = ( double )$this->ApplicablePages->ExtraPageMapData [$page] ['column-top'];
                    $this->Bottoms [$page] = ( double )$this->ApplicablePages->ExtraPageMapData [$page] ['column-bottom'];
                }

                $area = new CaptureArea($column, $this->ApplicablePages->ExtraPageMapData [$page], $column ['name']);

                $this->Areas [$page] [] = $area;
            }
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

        Support functions.

     *-------------------------------------------------------------------------------------------------------------*/
}
