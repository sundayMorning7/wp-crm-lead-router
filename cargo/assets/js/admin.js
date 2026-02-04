jQuery(function( $ ) {
    const fromField = $( 'input[name="md-date-from"]' )
    const toField = $( 'input[name="md-date-to"]' )

    // by default, the dates look like "April 3, 2017"
    // let's make them look like "2017-04-03" for convenience
    const customDateFormat = 'yy-mm-dd'

    // create datepickers
    fromField.datepicker( { dateFormat : customDateFormat } )
    toField.datepicker( { dateFormat : customDateFormat } )

    // prevent a user from choosing an incorrect date interval
    fromField.on( 'change', function() {
        toField.datepicker( 'option', 'minDate', fromField.val() )
    });
    toField.on( 'change', function() {
        fromField.datepicker( 'option', 'maxDate', toField.val() )
    });

});