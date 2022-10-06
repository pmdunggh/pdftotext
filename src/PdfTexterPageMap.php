<?php
namespace VanXuan\PdfToText;

/*==============================================================================================================

    PdfTexterPageMap -
        A class for detecting page objects mappings and retrieving page number for a specified object.
    There is a quadruple level of indirection here :

    - The first level contains a /Type /Catalog parameter, with a /Pages one that references an object which
      contains a /Count and /Kids. I don't know yet if the /Pages parameter can reference more than one
      object using the array notation. However, the class is designed to handle such situations.
    - The object containing the /Kids parameter references objects who, in turn, lists the objects contained
      into one single page.
    - Each object referenced in /Kids has a /Type/Page parameter, together with /Contents, which lists the
      objects of the current page.

    Object references are of the form : "x y R", where "x" is the object number.

    Of course, anything can be in any order, otherwise it would not be funny ! Consider the following
    example :

        (1) 5 0 obj
            << ... /Pages 1 0 R ... >>
            endobj

        (2) 1 0 obj
            << ... /Count 1 /Kids[6 0 R] ... /Type/Pages ... >>
            endobj

        (3)  6 0 obj
            << ... /Type/Page ... /Parent 1 0 R ... /Contents [10 0 R 11 0 R ... x 0 R]
             endobj

    Object #5 says that object #1 contains the list of page contents (in this example, there is only one page,
    referenced by object #6).
    Object #6 says that the objects #10, #11 through #x are contained into the same page.
    The quadruple indirection comes when you are handling one of the objects referenced in object #6 and you
    need to retrieve their page number...

    Of course, you cannot rely on the fact that all objects appear in logical order.

    And, of course #2, there may be no page catalog at all ! in such cases, objects containing drawing
    instructions will have to be considered as a single page, whose number will be sequential.

    And, of course #3, as this is the case with the official PDF 1.7 Reference from Adobe, there can be a
    reference to a non-existing object which was meant to contain the /Kids parameter (!). In this case,
    taking the ordinal number of objects of type (3) gives the page number minus one.

    One mystery is that the PDF 1.7 Reference file contains 1310 pages but only 1309 are recognized here...

  ==============================================================================================================*/

class PdfTexterPageMap extends PdfObjectBase
{
    // Page contents are (normally) first described by a catalog
    // Although there should be only one entry for that, this property is defined as an array, as you need to really
    // become paranoid when handling pdf contents...
    protected $PageCatalogs = [];
    // Entries that describe which page contains which text objects. Of course, these can be nested otherwise it would not be funny !
    protected $PageKids = [];
    // Terminal entries : they directly give the ids of the objects belonging to a page
    public $PageContents = [];
    // Note that all the above arrays are indexed by object id and filled with the data collected by calling the Peek() Method...

    // Objects that could be referenced from other text objects as XObjects, using the /TPLx notation
    protected $TemplateObjects = [];

    // Once the Peek() method has collected page contents & object information, the MapCatalog() method is called to create this array
    // which contains page numbers as keys, and the list of objects contained in this page as values
    public $Pages = [];
    // Holds page attributes
    public $PageAttributes = [];

    // Resource mappings can either refer to an object (/Resources 2 0 R) or to inline mappings (/Resources << ... >>)
    // The same object can be referenced by many /Resources parameters throughout the pdf file, so its important to keep
    // the analyzed mappings in a cache, so that later references will reuse the results of the first one
    private $ResourceMappingCache = [];
    // List of XObject names - Used by the IsValidTemplate() function
    private $XObjectNames = [];


    /*--------------------------------------------------------------------------------------------------------------

        CONSTRUCTOR
        Creates a PdfTexterPageMap object. Actually, nothing significant is perfomed here, as this class' goal
        is to be used internally by PdfTexter.

     *-------------------------------------------------------------------------------------------------------------*/
    public function __construct()
    {
        parent::__construct();
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            AddTemplateObject - Adds an object that could be referenced as a template/

        PROTOTYPE
            $pagemap -> AddTemplateObject ( $object_id, $object_text_data ) ;

        DESCRIPTION
            Adds an object that may be referenced as a template from another text object, using the /TPLx notation.

        PARAMETERS
            $object_id (integer) -
                    Id of the object that may contain a resource mapping entry.

        $object_data (string) -
            Object contents.

     *-------------------------------------------------------------------------------------------------------------*/
    public function AddTemplateObject($object_id, $object_text_data)
    {
        $this->TemplateObjects [$object_id] = $object_text_data;
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            GetResourceMappings - Gets resource mappings specified after a /Resources parameter.

        PROTOTYPE
            $result     =  $this -> GetResourceMappings ( $object_id, $object_data, $parameter, $pdf_object_list ) ;

        DESCRIPTION
            Most of the time, objects containing a page description (/Type /Page) also contain a /Resources parameter,
        which may be followed by one of the following constructs :
        - A reference to an object, such as :
            /Resources 2 0 R
        - Or an inline set of parameters, such as font or xobject mappings :
            /Resources << /Font<</F1 10 0 R ...>> /XObject <</Im0 27 0 R ...>>
        This method extracts alias/object mappings for the parameter specified by $parameter (it can be for
        example 'Font' or 'Xobject') and returns these mappings as an associative array.

        PARAMETERS
            $object_id (integer) -
                    Id of the object that may contain a resource mapping entry.

        $object_data (string) -
            Object contents.

        $parameter (string) -
            Parameter defining resource mapping, for example /Font or /XObject.

        $pdf_object_list (associative array) -
            Array of object id/object data associations, for all objects defined in the pdf file.

        RETURN VALUE
            The list of resource mappings for the specified parameter, as an associative array, whose keys are the
        resource aliases and values are the corresponding object ids.
        The method returns an empty array if the specified object does not contain resource mappings or does
        not contain the specified parameter.

     *-------------------------------------------------------------------------------------------------------------*/
    protected function GetResourceMappings($object_id, $object_data, $parameter, $pdf_object_list)
    {
        // The /Resources parameter refers to an existing PDF object
        if (preg_match('#/Resources \s* (?P<object_id> \d+) \s+ \d+ \s+ R#ix', $object_data, $match)) {
            // Return the cached result if the same object has previously been referenced by a /Resources parameter
            if (isset($this->ResourceMappingCache [$object_id] [$parameter])) {
                return ($this->ResourceMappingCache [$object_id] [$parameter]);
            }

            // Check that the object that is referred to exists
            if (isset($pdf_object_list [$match ['object_id']])) {
                $data = $pdf_object_list [$match ['object_id']];
            } else {
                return ([]);
            }

            $is_object = true;    // to tell that we need to put the results in cache for later use
        } elseif (preg_match('#/Resources \s* <#ix', $object_data, $match, PREG_OFFSET_CAPTURE)) {
            // The /Resources parameter is followed by inline mappings
            $data = substr($object_data, $match [0] [1] + strlen($match [0] [0]) - 1);
            $is_object = false;
        } else {
            return ([]);
        }

        // Whatever we will be analyzing (an object contents or inline contents following the /Resources parameter),
        // the text will be enclosed within double angle brackets (<< ... >>)

        // A small kludge for /XObject which specify an object reference ("15 0 R") instead of XObjects mappings
        // ("<< ...>>" )
        if ($parameter == '/XObject' && preg_match('#/XObject \s+ (?P<obj> \d+) \s+ \d+ \s+ R#ix', $data, $match)) {
            $data = '/XObject ' . $pdf_object_list [$match ['obj']];
        }

        if (preg_match("#$parameter \s* << \s* (?P<mappings> .*?) \s* >>#imsx", $data, $match)) {
            preg_match_all('# (?P<mapping> / [^\s]+) \s+ (?P<object_id> \d+) \s+ \d+ \s+ R#ix', $match ['mappings'], $matches);

            $mappings = [];

            // Mapping extraction loop
            for ($i = 0, $count = count($matches ['object_id']); $i < $count; $i++) {
                $mappings [$matches ['mapping'] [$i]] = $matches ['object_id'] [$i];
            }

            // Put results for referenced objects in cache
            if ($is_object) {
                $this->ResourceMappingCache [$object_id] [$parameter] = $mappings;
            }

            return ($mappings);
        } else {
            return ([]);
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            Peek - Peeks page information from a pdf object.

        PROTOTYPE
            $pagemap -> Peek ( ) ;

        DESCRIPTION
            Retrieves page information which can be of type (1), (2) or (3), as described in the class comments.

        PARAMETERS
            $object_id (integer) -
                    Id of the current pdf object.

        $object_data (string) -
            Pdf object contents.

        $pdf_objects (associative array) -
            Objects defined in the pdf file, as an associative array whose keys are object numbers and
            values object data.
            This parameter is used for /Type/Page objects which have a /Resource parameter that references
            an existing object instead of providing font mappings and other XObject mappings inline,
            enclosed within double angle brackets (<< /Font ... >>).

     *-------------------------------------------------------------------------------------------------------------*/
    public function Peek($object_id, $object_data, $pdf_objects)
    {
        // Page catalog (/Type/Catalog and /Pages x 0 R)
        if (preg_match('#/Type \s* /Catalog#ix', $object_data) && $this->GetObjectReferences($object_id, $object_data, '/Pages', $references)) {
            $this->PageCatalogs = array_merge($this->PageCatalogs, $references);
        } elseif (preg_match('#/Type \s* /Pages#ix', $object_data)) {
            // Object listing the object numbers that give the list of objects contained in a single page (/Types/Pages and /Count x /Kids[x1 0 R ... xn 0 R]
            if ($this->GetObjectReferences($object_id, $object_data, '/Kids', $references)) {
                // Sometimes, a reference can be the one of an object that contains the real reference ; in the following example,
                // the actual page contents are not in object 4, but in object 5
                //  /Kids 4 0 R
                //  ...
                //  4 0 obj
                //  [5 0 R]
                //  endobj
                $new_references = [];

                foreach ($references as $reference) {
                    if (!isset($pdf_objects [$reference]) ||
                        !preg_match('/^ \s* (?P<ref> \[ [^]]+ \]) \s*$/imsx', $pdf_objects [$reference], $match)
                    ) {
                        $new_references [] = $reference;
                    } else {
                        $this->GetObjectReferences($reference, $pdf_objects [$reference], '', $sub_references);
                        $new_references = array_merge($new_references, $sub_references);
                    }
                }

                // Get kid count (knowing that sometimes, it is missing...)
                preg_match('#/Count \s+ (?P<count> \d+)#ix', $object_data, $match);
                $page_count = (isset($match ['count'])) ? ( integer )$match ['count'] : false;

                // Get parent object id
                preg_match('#/Parent \s+ (?P<parent> \d+)#ix', $object_data, $match);
                $parent = (isset($match ['parent'])) ? ( integer )$match ['parent'] : false;

                $this->PageKids [$object_id] =
                [
                    'object' => $object_id,
                    'parent' => $parent,
                    'count' => $page_count,
                    'kids' => $new_references
                ];
            }
        } elseif (preg_match('#/Type \s* /Page\b#ix', $object_data)) {
            // Object listing the other objects that are contained in this page (/Type/Page and /Contents[x1 0 R ... xn 0 R]
            if ($this->GetObjectReferences($object_id, $object_data, '/Contents', $references)) {
                preg_match('#/Parent \s+ (?P<parent> \d+)#ix', $object_data, $match);
                $parent = (isset($match ['parent'])) ? (integer)$match ['parent'] : false;
                $fonts = $this->GetResourceMappings($object_id, $object_data, '/Font', $pdf_objects);
                $xobjects = $this->GetResourceMappings($object_id, $object_data, '/XObject', $pdf_objects);

                // Find the width and height of the page (/Mediabox parameter)
                if (preg_match('#/MediaBox \s* \[ \s* (?P<x1> \d+) \s+ (?P<y1> \d+) \s+ (?P<x2> \d+) \s+ (?P<y2> \d+) \s* \]#imsx', $object_data, $match)) {
                    $width = ( double )($match ['x2'] - $match ['x1'] + 1);
                    $height = ( double )($match ['y2'] - $match ['y1'] + 1);
                } else {
                    // Otherwise, fix an arbitrary width and length (but this should never happen, because all pdf files are correct, isn't it?)
                    $width = 595;
                    $height = 850;
                }

                // Yes ! some /Contents parameters may designate another object which contains references to the real text contents
                // in the form : [x 0 R y 0 R etc.], so we have to dig into it...
                $new_references = [];

                foreach ($references as $reference) {
                    // We just need to check that the object contains something like :
                    //  [x 0 R y 0 R ...]
                    // and nothing more
                    if (isset($pdf_objects [$reference]) && preg_match('#^\s* \[ [^]]+ \]#x', $pdf_objects [$reference]) &&
                        $this->GetObjectReferences($reference, $pdf_objects [$reference], '', $nested_references)
                    ) {
                        $new_references = array_merge($new_references, $nested_references);
                    } else {
                        $new_references [] = $reference;
                    }
                }

                $this->PageContents [$object_id] =
                [
                    'object' => $object_id,
                    'parent' => $parent,
                    'contents' => $new_references,
                    'fonts' => $fonts,
                    'xobjects' => $xobjects,
                    'width' => $width,
                    'height' => $height
                ];
            }
        } elseif (preg_match('#/Type \s* /XObject\b#ix', $object_data)) {
            // None of the above, but object contains /Xobject's and maybe more...
            preg_match('#/Parent \s+ (?P<parent> \d+)#ix', $object_data, $match);
            $parent = (isset($match ['parent'])) ? (integer)$match ['parent'] : false;
            $fonts = $this->GetResourceMappings($object_id, $object_data, '/Font', $pdf_objects);
            $xobjects = $this->GetResourceMappings($object_id, $object_data, '/XObject', $pdf_objects);

            $this->GetObjectReferences($object_id, $object_data, '/Contents', $references);

            $this->PageContents [$object_id] =
            [
                'object' => $object_id,
                'parent' => $parent,
                'contents' => $references,
                'fonts' => $fonts,
                'xobjects' => $xobjects
            ];
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            ProcessTemplateReferences - Replace template references with actual text contents.

        PROTOTYPE
            $text       =  $pagemap -> ReplaceTemplateReferences ( $page_number, $text_data ) ;

        DESCRIPTION
            Replaces template references of the form "/TPLx Do" with the actual text contents.

        PARAMETERS
            $page_number (integer) -
                    Page number of the object that contains the supplied object data.

        $text_data (string)
            Text drawing instructions that are to be processed.

        RETURN VALUE
            Returns the original text, where all template references have been replaced with the contents of the
        object they refer to.

     *-------------------------------------------------------------------------------------------------------------*/
    public function ProcessTemplateReferences($page_number, $text_data)
    {
        // Many paranoid checks in this piece of code...
        if (isset($this->Pages [$page_number])) {
            // Loop through the PageContents array to find which one(s) may be subject to template reference replacements
            foreach ($this->PageContents as $page_contents) {
                // If the current object relates to the specified page number, AND it has xobjects, then the supplied text data
                // may contain template reference of the form : /TPLx.
                // In this case, we replace such a reference with the actual contents of the object they refer to
                if (isset($page_contents ['page']) && $page_contents ['page'] == $page_number && count($page_contents ['xobjects'])) {
                    $template_searches = [];
                    $template_replacements = [];

                    $this->__get_replacements($page_contents, $template_searches, $template_replacements);
                    $text_data = self::PregStrReplace($template_searches, $template_replacements, $text_data);
                }
            }
        }

        return ($text_data);
    }


    // __get_replacements -
    //  Recursively gets the search/replacement strings for template references.
    private function __get_replacements($page_contents, &$searches, &$replacements, $objects_seen = [])
    {
        foreach ($page_contents ['xobjects'] as $template_name => $template_object) {
            if (isset($this->TemplateObjects [$template_object]) && !isset($objects_seen [$template_object])) {
                $template = $this->TemplateObjects [$template_object];
                $searches [] = '#(' . $template_name . ' \s+ Do\b )#msx';
                $replacements [] = '!PDFTOTEXT_TEMPLATE_' . substr($template_name, 1) . ' ' . $template;
                $objects_seen [$template_object] = $template_object;

                if (isset($this->PageContents [$template_object])) {
                    $this->__get_replacements($this->PageContents [$template_object], $searches, $replacements, $objects_seen);
                }
            }
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            MapObjects - Builds a correspondance between object and page numbers.

        PROTOTYPE
            $pagemap -> MapObjects ( ) ;

        DESCRIPTION
            Builds a correspondance between object and page numbers. The page number corresponding to an object id
        will after that be available using the array notation.

        NOTES
        This method behaves as if there could be more than one page catalog in the same file, but I've not yet
        encountered this case.

     *-------------------------------------------------------------------------------------------------------------*/
    public function MapObjects($objects)
    {
        $kid_count = count($this->PageKids);

        // PDF files created short after the birth of Earth may have neither a page catalog nor page contents descriptions
        if (!count($this->PageCatalogs)) {
            // Later, during Pleistocen, references to page kids started to appear...
            if ($kid_count) {
                foreach (array_keys($this->PageKids) as $catalog) {
                    $this->MapKids($catalog, $current_page);
                }
            } else {
                $this->Pages [1] = array_keys($objects);
            }
        } else {
            // This is the ideal situation : there is a catalog that allows us to gather indirectly all page data
            $current_page = 1;

            foreach ($this->PageCatalogs as $catalog) {
                if (isset($this->PageKids [$catalog])) {
                    $this->MapKids($catalog, $current_page);
                } else {
                    // Well, almost ideal : it may happen that the page catalog refers to a non-existing object :
                    // in this case, we behave the same as if there were no page catalog at all : group everything
                    // onto one page
                    $this->Pages [1] = array_keys($objects);
                }
            }
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            MapKids - Establishes a correspondance between page kids and a current page number.

        PROTOTYPE
            $pagemap -> MapObjects ( $catalog, &$page ) ;

        DESCRIPTION
        Tries to assign a page number to all page description objects that have been collected by the Peek()
        method.
        Also creates the Pages associative array, whose keys are page numbers and whose values are the ids of
        the objects that the page contains.

        EXAMPLE
        The following example gives an overview of a possible layout for page catalogs ; it describes which
        objects contain what.
        Lines starting with "#x", where "x" is a number, stands for a PDF object definition, which will start
        with "x 0 obj" in the PDF file.
        Whenever numbers are referenced (other than those prefixed with a "#"), it means "reference to the
        specified object.
        For example, "54" will refer to object #54, and will be given as "54 0 R" in the PDF file.
        The numbers at the beginning of each line are just "step numbers", which will be referenced in the
        explanations after the example :

            (01) #1 : /Type/Catalog /Pages 54
            (02)    -> #54 : /Type/Pages /Kids[3 28 32 58] /Count 5
            (03)           -> #3 : /Type/Page /Parent 54 /Contents[26]
            (04)             -> #26 : page contents
            (05)           -> #28 : /Type/Page /Parent 54 /Contents[30 100 101 102 103 104]
            (06)             -> #30 : page contents
            (07)           -> #32 : /Type/Page /Parent 54 /Contents[34]
            (08)             -> #34 : page contents
            (09)           -> #58 : /Type/Pages /Parent 54 /Count 2 /Kids[36 40]
            (10)             -> #36 : /Type/Page /Parent 58 /Contents[38]
            (11)                -> #38 : page contents
            (12)             -> #40 : /Type/Page /Parent 58 /Contents[42]
            (13)                -> #42 : page contents

         Explanations :
            (01) Object #1 contains the page catalog ; it states that a further description of the page
                 contents is given by object #54.
                 Note that it could reference multiple page descriptions, such as : /Pages [54 68 99...]
                 (although I did not met the case so far)
            (02) Object #54 in turn says that it as "kids", described by objects #3, #28, #32 and #58. It
                 also says that it has 5 pages (/Count parameter) ; but wait... the /Kids parameter references
                 4 objects while the /Count parameter states that we have 5 pages : what happens ? we will
                 discover it in the explanations below.
            (03) Object #3 states that it is aimed for page description (/Type/Page) ; the page contents
                 will be found in object #26, specified after the /Contents parameter. Note that here again,
                 multiple objects could be referenced by the /Contents parameter but, in our case, there is
                 only one, 26. Object #3 also says that its parent object (in the page catalog) is object
                 #54, defined in (01).
                 Since this is the first page we met, it will have page number 1.
            (04) ... object #26 contains the Postscript instructions to draw page #1
            (05) Object #28 has the same type as #3 ; its page contents can be located in object #30 (06)
                 The same applies for object #32 (07), whose page contents are given by object #34 (08).
                 So, (05) and (07) will be pages 2 and 3, respectively.
            (09) Now, it starts to become interesting : object #58 does not directly lead to an object
                 containing Postscript instructions as did objects #3, #28 and #32 whose parent is #54, but
                 to yet another page catalog which contains 2 pages (/Count 2), described by objects #36 and
                 #40. It's not located at the same position as object #54 in the hierarchy, so it shows that
                 page content descriptions can be recursively nested.
            (10) Object #36 says that we will find the page contents in object #38 (which will be page 4)
            (12) ... and object #40 says that we will find the page contents in object #42 (and our final
                 page, 5)

     *-------------------------------------------------------------------------------------------------------------*/
    protected function MapKids($catalog, &$page)
    {
        if (!isset($this->PageKids [$catalog])) {
            return;
        }

        $entry = $this->PageKids [$catalog];

        // The PDF file contains an object containing a /Type/Pages/Kids[] construct, specified by another object containing a
        // /Type/Catalog/Pages construct : we will rely on its contents to find which page contains what
        if (isset($this->PageContents [$entry ['kids'] [0]])) {
            foreach ($entry ['kids'] as $item) {
                // Some objects given by a /Page /Contents[] construct do not directly lead to an object describing PDF contents,
                // but rather to an object containing in turn a /Pages /Kids[] construct ; this adds a level of indirection, and
                // we have to recursively process it
                if (isset($this->PageKids [$item])) {
                    $this->MapKids($item, $page);
                } else {
                    // The referenced object actually defines page contents (no indirection)
                    $this->PageContents [$item]    ['page'] = $page;
                    $this->Pages [$page] = (isset($this->PageContents [$item] ['contents'])) ?
                        $this->PageContents [$item] ['contents'] : [];
                    if (isset($this->PageContents [$item] ['width'])) {
                        $this->PageAttributes [$page] =
                        [
                            'width' => $this->PageContents [$item] ['width'],
                            'height' => $this->PageContents [$item] ['height']
                        ];
                    }

                    $page++;
                }
            }
        } else {
            // No page catalog at all : consider everything is on the same page (this class does not use the WheresMyCrystalBall trait)
            foreach ($entry ['kids'] as $kid) {
                $this->MapKids($kid, $page);
            }
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            GetMappedFonts - Retrieves the mapped fonts per page

        PROTOTYPE
            $array  =  $pagemap -> GetMappedFonts ( ) ;

        DESCRIPTION
            Gets the mapped fonts, per page. XObjects are traversed, to retrieved additional font aliases defined
        by them.
        This function is used by the PdfTexter class to add additional entries to the FontMap object,
        ensuring that each reference to a font remains local to a page.

        RETURN VALUE
            Returns an array of associative arrays which have the following entries :
        - 'page' :
            Page number.
        - 'xobject-name' :
            XObject name, that can define further font aliases. This entry is set to the empty string for
            global font aliases.
        - 'font-name' :
            Font name (eg, "/F1", "/C1_0", etc.).
        - 'object' :
            Object defining the font attributes, such as character map, etc.

     *-------------------------------------------------------------------------------------------------------------*/
    public function GetMappedFonts()
    {
        $mapped_fonts = [];
        $current_page = 0;

        foreach ($this->PageCatalogs as $catalog) {
            if (!isset($this->PageKids [$catalog])) {
                continue;
            }

            foreach ($this->PageKids [$catalog] ['kids'] as $page_object) {
                $current_page++;

                if (isset($this->PageContents [$page_object])) {
                    $page_contents = $this->PageContents [$page_object];
                    $associations = [];

                    if (isset($page_contents ['fonts'])) {
                        foreach ($page_contents ['fonts'] as $font_name => $font_object) {
                            $mapped_fonts [] =
                            [
                                'page' => $current_page,
                                'xobject-name' => '',
                                'font-name' => $font_name,
                                'object' => $font_object
                            ];

                            $associations [":$font_name"] = $font_object;

                            $this->__map_recursive($current_page, $page_contents ['xobjects'], $mapped_fonts, $associations);
                        }
                    }
                }
            }
        }

        return ($mapped_fonts);
    }


    // __map_recursive -
    //  Recursively collects font aliases for XObjects.
    private function __map_recursive($page_number, $xobjects, &$mapped_fonts, &$associations)
    {
        foreach ($xobjects as $xobject_name => $xobject_value) {
            if (isset($this->PageContents [$xobject_value])) {
                foreach ($this->PageContents [$xobject_value] ['fonts'] as $font_name => $font_object) {
                    if (!isset($associations ["$xobject_name:$font_name"])) {
                        $mapped_fonts [] =
                        [
                            'page' => $page_number,
                            'xobject-name' => $xobject_name,
                            'font-name' => $font_name,
                            'object' => $font_object
                        ];

                        $associations ["$xobject_name:$font_name"] = $font_object;
                    }
                }

                $this->XObjectNames [$xobject_name] = 1;
                $this->__map_recursive($page_number, $this->PageContents [$xobject_value] ['xobjects'], $mapped_fonts, $associations);
            }
        }
    }


    /*--------------------------------------------------------------------------------------------------------------

        NAME
            IsValidXObject - Checks if the specified object is a valid XObject.

        PROTOTYPE
            $status     =  $pagemap -> IsValidXObjectName ( $name ) ;

        DESCRIPTION
            Checks if the specified name is a valid XObject defining its own set of font aliases.

        PARAMETERS
            $name (string) -
                    Name of the XObject to be checked.

        RETURN VALUE
            Returns true if the specified XObject exists and defines its own set of font aliases, false otherwise.

     *-------------------------------------------------------------------------------------------------------------*/
    public function IsValidXObjectName($name)
    {
        return (isset($this->XObjectNames [$name]));
    }
}
