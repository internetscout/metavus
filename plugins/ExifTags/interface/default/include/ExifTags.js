/**
 * FILE:  ExifTags.js
 *
 * Part of the Metavus digital collections platform
 * Copyright 2023 Edward Almasy and Internet Scout Research Group
 * http://metavus.net
 *
 * Extends and manipulates the form for the Exif Tags plugin's
 * "Configure Mappings" page that configures mappings from EXIF tags to
 * schemas' metadata fields.
 *
 * Expects the variable ValidTagTypes (JSON encoded version of $H_ExifTags)
 * to be set.
 *
 * @scout:eslint
 */


(function() {

    /**
     *  When a Delete button is clicked, hide that row and set the hidden
     *  "Delete" input's value for that row to "true".
     */
    function deleteHandler() {
        var Row = $(this).parent().parent();
        Row.hide();
        $("input[type='hidden'][name^='F_Delete']", Row).val("true");
    }

    /**
     *  Show and hide options for metadata fields in a drop down to select
     *  which of a schema's metadata fields values from an EXIF tag will be
     *  assigned to, based on which of the metadata fields have types that
     *  can have the value of the selected EXIF tag assigned to.
     *  @param ExifTagDropdown Dropdown that selects an EXIF tag for defining a
     *          mapping from the EXIF tag to one of a schema's metadata fields.
     */
    function showFieldOptionsWithMatchingTypes(ExifTagDropdown) {
        var AllowableTypesForTag;
        var AllowableTypeStyles;
        var NewTagTypes;
        var NewTag = $(":selected", ExifTagDropdown).val();
        var Row = ExifTagDropdown.parent().parent();
        var LocalFieldDropdown = $(".mv-localfields", Row);
        var LocalFieldSelection;

        LocalFieldDropdown.removeAttr("disabled");
        NewTagTypes = ValidTagTypes[NewTag]; // eslint-disable-line no-undef
        if (NewTag == 0) {
            // if "--" (blank tag) has been selected, no field types are
            // allowed to be assigned, the local field drop down is disabled
            AllowableTypesForTag = [];
            LocalFieldDropdown.attr("disabled", "true");
        } else {
            AllowableTypesForTag = NewTagTypes.AllowableTypes;
        }
        AllowableTypeStyles =
                AllowableTypesForTag.map(
                    Type => ".option-type-"+Type
                ).join(",");

        // if the selected tag changes, it may be that some fields that were
        // hidden should now be shown
        $("option", LocalFieldDropdown).show();

        // that some fields that were hidden should now be shown
        $("option", LocalFieldDropdown).not(AllowableTypeStyles).hide();

        // the current selection does not have a type allowed for the selected
        // tag
        LocalFieldSelection = $("option:selected", LocalFieldDropdown);
        if (LocalFieldSelection.not(AllowableTypeStyles).length == 1) {
            LocalFieldDropdown.val(0);
        }
    }

    /**
     *  Add an event listener to all of the EXIF Tags drop downs.
     *  When the value of an EXIF Tag drop down is changed, the local field drop
     *  down on that row will have it's the options for metadata fields in that
     *  schema hidden and shown according to which fields in that section's
     *  schema have types can recieve values from the selected EXIF tag.
     */
    function watchForTagChange() {
        var ExifTagDropdowns = $("select.mv-tags");
        $(ExifTagDropdowns).change(function() {
            showFieldOptionsWithMatchingTypes($(this));
        });
    }

    /**
     *  Concatenate the given prefix and row key and set both the name and ID
     *  attributes of the specified element to that value.
     *  Convenience method for adjustRowAfterClone.
     *  @param Element to set name and ID attributes of.
     *  @param Prefix to use as the first part of the name and ID attributes
     *          of the specified element. Should begin with "F_" and end with
     *          "-".
     *  @param RowKey Unique key created from schema ID and integer index for
     *          identifying an element based on which row it is in.
     */
    function setNameAndIdWithRowKey(Element, Prefix, RowKey) {
        Element.attr("id", Prefix + RowKey);
        Element.attr("name", Prefix + RowKey);
    }

    /**
     * Change the name and ID on the row parameter to reflect that it is no
     * longer the bottom row. It receives unique name and ID attributes.
     * @param Row Table row element that shows tag and field for a new mapping.
     * @param SchemaId ID of the schema that the table containing the row with a
     *         new mapping shows mappings for.
     * @param IndexToUse Numeric index for the row with the new mapping.
     */
    function adjustRowAfterClone(Row, SchemaId, IndexToUse){
        var DeleteButton;
        var HiddenInput;
        var RowKey;
        Row.removeClass("mv-new_mapping_template");

        // use to make controls have distinct IDs that match per-row
        RowKey = SchemaId + "-" + IndexToUse;
        Row.attr("data-uniqueid", "mapping-schema-" + RowKey);

        DeleteButton = $("input[name='Delete']", Row);
        DeleteButton.show();
        DeleteButton.click(deleteHandler);

        setNameAndIdWithRowKey(DeleteButton, "F_Delete-", RowKey);

        HiddenInput = $("input[type='hidden'][name^='F_Deleted']", Row);
        HiddenInput.attr("name", "F_Deleted-" + RowKey);
        setNameAndIdWithRowKey(HiddenInput, "F_Deleted-", RowKey);

        // IDs of controls must be unique
        setNameAndIdWithRowKey($(".mv-tags", Row), "F_ExifPicker-", RowKey);
        setNameAndIdWithRowKey(
            $(".mv-localfields", Row),
            "F_LocalField-", RowKey
        );
    }

    /**
     * When a selection is made in the drop down for metadata field on the
     * template row, clone that row and append the clone to the end of the
     * table of mappings for the specified schema to serve as the new template
     * row.
     */
    function watchForLocalFieldChange() {
        var AllRowsInSchema;
        var FreshCopy;
        var IndexToUse;
        var Row;
        var SchemaId;

        $(".mv-localfields", "tr.mv-new_mapping_template").change(function() {
            Row = $(this).parent().parent();

            if (!Row.hasClass("mv-new_mapping_template")) {
                return;
            }
            SchemaId = Row.data("schemaid");
            AllRowsInSchema = $("tr[data-schemaid='" + SchemaId + "']");
            // exclude the bottom blank row from the count
            IndexToUse = AllRowsInSchema.length - 1;

            // clone(true) to copy events and handlers over to the clone
            FreshCopy = Row.clone(true);
            $(".mv-localfields", FreshCopy).attr("disabled", "true");
            FreshCopy.insertAfter(Row);

            adjustRowAfterClone(Row, SchemaId, IndexToUse);
        });
    }

    /**
     *  On page load, setup the form for mappings from EXIF tags to metadata
     *  fields. Disable the metadata field drop-downs on the template rows at
     *  the bottom ofthe section for each schema. Hide drop down options for
     *  metadata fields that can not be mapped to the EXIFs tag already
     *  selected for existing mappings. Hide "Delete" buttons on the bottom row
     *  of each section. Set up event listeners for delete buttons and
     *  drop-downs.
     */
    $(document).ready(function() {
        // dropdown for the new mapping's metadata field is initially disabled

        $(".mv-localfields", "tr.mv-new_mapping_template"
        ).attr("disabled", true);

        $("select.mv-tags").each(function() {
            showFieldOptionsWithMatchingTypes($(this));
        });

        $("input[name='Delete']", "tr.mv-new_mapping_template").hide();
        $("input[name='Delete']").click(deleteHandler);
        watchForTagChange();
        watchForLocalFieldChange();
    });
}());
