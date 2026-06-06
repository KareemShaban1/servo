

<script type="text/javascript">
    $(document).ready( function() {

        $('#subcategories-select').select2({
            placeholder: "Select subcategories",
            allowClear: true
        });
        // function getTaxonomiesIndexPage () {
        //     var data = {category_type : $('#category_type').val()};
        //     $.ajax({
        //         method: "GET",
        //         dataType: "html",
        //         url: '/taxonomies-ajax-index-page',
        //         data: data,
        //         async: false,
        //         success: function(result){
        //             console.log(result);
        //             $('.taxonomy_body').html(result);
        //         }
        //     });
        // }

        function getTaxonomiesIndexPage() {
    var data = {category_type : $('#category_type').val()};
    $.ajax({
        method: "GET",
        dataType: "html",
        url: '/taxonomies-ajax-index-page',
        data: data,
        success: function(result){
            // Destroy previous DataTable instance if exists
            if ($.fn.DataTable.isDataTable('#category_table')) {
                $('#category_table').DataTable().clear().destroy();
            }

            // Inject new HTML
            $('.taxonomy_body').html(result);

            // Re-init DataTable
            $('#category_table').DataTable({
                processing: true,
                serverSide: false, // or true if you plan to load via ajax
                searching: true,
                paging: true,
                
            });
        }
    });
}


        function initializeTaxonomyDataTable() {
            //Category table
            if ($('#category_table').length) {
                var category_type = $('#category_type').val();
                category_table = $('#category_table').DataTable({
                    processing: true,
                    serverSide: true,
                    ajax: '/taxonomies?type=' + category_type,
                    columns: [
                        { data: 'image', name: 'image' },
                        { data: 'name', name: 'name' },
                        { data: 'sub_categories', name: 'sub_categories' },
                        { data: 'category_type', name: 'category_type' },
                        { data: 'sort_order', name: 'sort_order' },
                        @if($cat_code_enabled)
                            { data: 'short_code', name: 'short_code' },
                        @endif
                        { data: 'description', name: 'description' },
                        { data: 'action', name: 'action', orderable: false, searchable: false},
                    ],
                });
            }
        }

        @if(empty(request()->get('type')))
            getTaxonomiesIndexPage();
        @endif

        initializeTaxonomyDataTable();
    });
    $(document).on('submit', 'form#category_add_form', function(e) {
        e.preventDefault();
        var form = $(this);
        let formData = new FormData(this);

        $.ajax({
            method: 'POST',
            url: $(this).attr('action'),
            data: formData,
            dataType: 'json',
            processData: false,
            contentType: false,
            beforeSend: function(xhr) {
                __disable_submit_button(form.find('button[type="submit"]'));
            },
            success: function(result) {
                if (result.success === true) {
                    $('div.category_modal').modal('hide');
                    toastr.success(result.msg);
                    window.location.reload();
                } else {
                    toastr.error(result.msg);
                }
            },
        });
    });

    $(document).on('submit', 'form#category_edit_form', function(e) {
        e.preventDefault();
        var form = $(this);
        let formData = new FormData(this);

        if (!formData.has('sort_order')) {
            formData.append('sort_order', form.find('[name="sort_order"]').val() || 0);
        }

        $.ajax({
            method: 'POST',
            url: $(this).attr('action'),
            data: formData,
            dataType: 'json',
            processData: false,
            contentType: false,
            beforeSend: function(xhr) {
                __disable_submit_button(form.find('button[type="submit"]'));
            },
            success: function(result) {
                if (result.success === true) {
                    $('div.category_modal').modal('hide');
                    toastr.success(result.msg);
                    if (typeof category_table !== 'undefined' && category_table) {
                        category_table.ajax.reload(null, false);
                    } else {
                        window.location.reload();
                    }
                } else {
                    toastr.error(result.msg);
                }
            },
        });
    });

    $(document).on('click', 'button.edit_category_button', function() {
        $('div.category_modal').load($(this).data('href'), function() {
            $(this).modal('show');
        });
    });

    $(document).on('click', 'button.delete_category_button', function() {
        swal({
            title: LANG.sure,
            icon: 'warning',
            buttons: true,
            dangerMode: true,
        }).then(willDelete => {
            if (willDelete) {
                var href = $(this).data('href');
                var data = $(this).serialize();

                $.ajax({
                    method: 'DELETE',
                    url: href,
                    dataType: 'json',
                    data: data,
                    success: function(result) {
                        if (result.success === true) {
                            toastr.success(result.msg);
                            // category_table.ajax.reload();
                            window.location.reload();

                        } else {
                            toastr.error(result.msg);
                        }
                    },
                });
            }
        });
    });
</script>