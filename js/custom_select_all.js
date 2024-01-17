(function ($, Drupal, once) {
  Drupal.behaviors.customSelectAll = {
    attach: function (context, settings) {
      // Create the link element
      var selectAllLink = $('<a href="#" id="edit-select-unselect-all">Select/Unselect All</a>');

      // Use `once()` to ensure the link is prepended only once
      $(once('custom-select-all', '#edit-embargoed-file', context)).prepend(selectAllLink);

      // Attach the click handler directly to the link element
      selectAllLink.on('click', function (event) {
        event.preventDefault();

        var checkboxes = $(':checkbox', context); // Target checkboxes within relevant context

        // Toggle checked state based on current state of the first checkbox
        checkboxes.prop('checked', !checkboxes.first().prop('checked'));
      });
    }
  };
})(jQuery, Drupal, once);
