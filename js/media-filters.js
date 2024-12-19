var MediaLibraryTaxonomyFilter = wp.media.view.AttachmentFilters.extend({
    id: 'media-attachment-taxonomy-filter',
    createFilters: function() {
        var filters = {};
        // Assuming you have localized your terms
        _.each(MediaLibraryTaxonomyFilterData.terms || {}, function(value, index) {
            filters[index] = {
                text: value.name,
                props: {
                    collection: value.slug // Adjust this to your taxonomy
                }
            };
        });
        filters.all = {
            text: 'All Items',
            props: {
                collection: ''
            },
            priority: 10
        };
        this.filters = filters;
    }
});


var AttachmentsBrowser = wp.media.view.AttachmentsBrowser;
wp.media.view.AttachmentsBrowser = AttachmentsBrowser.extend({
    createToolbar: function() {
        AttachmentsBrowser.prototype.createToolbar.call(this);
        this.toolbar.set('MediaLibraryTaxonomyFilter', new MediaLibraryTaxonomyFilter({
            controller: this.controller,
            model: this.collection.props,
            priority: -75
        }).render());
        
    }
});


// Create state
var myCustomState = wp.media.controller.Library.extend({
    defaults :  _.defaults({
        id: 'my-custom-state',
        title: 'Upload Image',
        allowLocalEdits: true,
        displaySettings: true,
        filterable: 'all', // This is the property you need. Accepts 'all', 'uploaded', or 'unattached'.
        displayUserSettings: true,
        multiple : false,
    }, wp.media.controller.Library.prototype.defaults )
});

//Setup media frame
frame = wp.media({
    button: {
        text: 'Select'
    },
    state: 'my-custom-state', // set the custom state as default state
    states: [
        new myCustomState() // add the state
    ]
});
