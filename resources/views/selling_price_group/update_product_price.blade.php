@extends('layouts.app')
@section('title', __('lang_v1.update_product_price'))

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1 class="tw-text-xl md:tw-text-3xl tw-font-bold tw-text-black">@lang( 'lang_v1.update_product_price' )
    </h1>
    <!-- <ol class="breadcrumb">
        <li><a href="#"><i class="fa fa-dashboard"></i> Level</a></li>
        <li class="active">Here</li>
    </ol> -->
</section>

<!-- Main content -->
<section class="content">
    @if (session('notification') || !empty($notification))
        <div class="row">
            <div class="col-sm-12">
                <div class="alert alert-danger alert-dismissible">
                    <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                    @if(!empty($notification['msg']))
                        {{$notification['msg']}}
                    @elseif(session('notification.msg'))
                        {{ session('notification.msg') }}
                    @endif
                </div>
            </div>  
        </div>     
    @endif
    @component('components.widget', ['class' => 'box-primary', 'title' => __('lang_v1.import_export_product_price')])
            <div class="row">
                <div class="col-sm-6">
                    <a href="{{action([\App\Http\Controllers\SellingPriceGroupController::class, 'export'])}}" class="tw-dw-btn tw-dw-btn-primary tw-text-white">@lang('lang_v1.export_product_prices')</a>
                </div>
                <div class="col-sm-6">
                    {!! Form::open(['url' => action([\App\Http\Controllers\SellingPriceGroupController::class, 'import']), 'method' => 'post', 'enctype' => 'multipart/form-data', 'id' => 'product_price_import_form' ]) !!}
                    <div class="form-group">
                        {!! Form::label('name', __( 'product.file_to_import' ) . ':') !!}
                        {!! Form::file('product_group_prices', ['required' => 'required']); !!}
                    </div>
                    <div class="form-group">
                        <button type="submit" class="tw-dw-btn tw-dw-btn-primary tw-text-white" id="import_submit_btn">@lang('messages.submit')</button>
                        <button type="button" class="tw-dw-btn tw-dw-btn-danger tw-text-white hide" id="cancel_import_btn">@lang('messages.cancel')</button>
                    </div>
                    <div id="import_progress_wrapper" class="hide">
                        <p class="tw-mb-2"><strong>@lang('lang_v1.processing')</strong> <span id="import_progress_text">0%</span></p>
                        <div class="progress">
                            <div id="import_progress_bar" class="progress-bar progress-bar-info progress-bar-striped active" role="progressbar" aria-valuemin="0" aria-valuemax="100" style="width: 0%;">
                                0%
                            </div>
                        </div>
                        <div id="import_result_message" class="tw-mt-2"></div>
                        <ul id="import_result_details" class="tw-mt-2 tw-pl-4"></ul>
                    </div>
                    {!! Form::close() !!}
                </div>
                <div class="col-sm-12">
                    <h4>@lang('lang_v1.instructions'):</h4>
                    <ol>
                        <li>@lang('lang_v1.price_import_instruction_1')</li>
                        <li>@lang('lang_v1.price_import_instruction_2')</li>
                        <li>@lang('lang_v1.price_import_instruction_3')</li>
                        <li>@lang('lang_v1.price_import_instruction_4')</li>
                    </ol>
                    
                </div>
            </div>
    @endcomponent
    

</section>
<!-- /.content -->
@stop

@section('javascript')
<script type="text/javascript">
    var currentImportRequest = null;
    var importCancelledByUser = false;

    $(document).on('submit', 'form#product_price_import_form', function(e) {
        e.preventDefault();

        if (currentImportRequest) {
            return false;
        }

        var $form = $(this);
        var fileInput = $form.find('input[name="product_group_prices"]')[0];
        if (!fileInput.files || !fileInput.files.length) {
            toastr.error("{{ __('product.file_to_import') }}");
            return false;
        }

        var $submitBtn = $('#import_submit_btn');
        var $progressWrapper = $('#import_progress_wrapper');
        var $progressBar = $('#import_progress_bar');
        var $progressText = $('#import_progress_text');
        var $resultMessage = $('#import_result_message');
        var $resultDetails = $('#import_result_details');
        var $cancelBtn = $('#cancel_import_btn');

        importCancelledByUser = false;
        $submitBtn.prop('disabled', true);
        $cancelBtn.removeClass('hide').prop('disabled', false);
        $progressWrapper.removeClass('hide');
        $resultMessage.html('');
        $resultDetails.html('');
        $progressText.text('0%');
        $progressBar.css('width', '0%').attr('aria-valuenow', 0).text('0%')
            .removeClass('progress-bar-success progress-bar-danger')
            .addClass('progress-bar-info progress-bar-striped active');

        var formData = new FormData($form[0]);

        currentImportRequest = $.ajax({
            url: $form.attr('action'),
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function() {
                var xhr = $.ajaxSettings.xhr();
                if (xhr.upload) {
                    xhr.upload.addEventListener('progress', function(evt) {
                        if (evt.lengthComputable) {
                            var percent = Math.round((evt.loaded / evt.total) * 100);
                            $progressText.text(percent + '%');
                            $progressBar.css('width', percent + '%').attr('aria-valuenow', percent).text(percent + '%');
                        }
                    }, false);
                }

                return xhr;
            },
            success: function(response) {
                $progressText.text('100%');
                $progressBar.css('width', '100%').attr('aria-valuenow', 100).text('100%')
                    .removeClass('progress-bar-info')
                    .addClass('progress-bar-success');

                var msg = response.msg || "{{ __('lang_v1.product_prices_imported_successfully') }}";
                $resultMessage.html('<div class="alert alert-success">' + msg + '</div>');
                toastr.success(msg);

                if (response.details) {
                    $resultDetails.html(
                        '<li>Total rows processed: ' + (response.details.total_rows || 0) + '</li>' +
                        '<li>Product names updated: ' + (response.details.updated_product_names || 0) + '</li>' +
                        '<li>Base prices updated: ' + (response.details.updated_base_prices || 0) + '</li>' +
                        '<li>Group prices updated: ' + (response.details.updated_group_prices || 0) + '</li>'
                    );
                }
            },
            error: function(xhr) {
                if (importCancelledByUser || xhr.statusText === 'abort') {
                    $resultMessage.html('<div class="alert alert-warning">Import cancelled.</div>');
                    toastr.warning('Import cancelled.');
                    return;
                }

                var errMsg = "{{ __('messages.something_went_wrong') }}";
                if (xhr.responseJSON && xhr.responseJSON.msg) {
                    errMsg = xhr.responseJSON.msg;
                }

                $progressBar.removeClass('progress-bar-info progress-bar-success')
                    .addClass('progress-bar-danger')
                    .removeClass('active');
                $resultMessage.html('<div class="alert alert-danger">' + errMsg + '</div>');
                toastr.error(errMsg);
            },
            complete: function() {
                currentImportRequest = null;
                $submitBtn.prop('disabled', false);
                $cancelBtn.addClass('hide').prop('disabled', true);
                $progressBar.removeClass('active');
            }
        });
    });

    $(document).on('click', '#cancel_import_btn', function() {
        if (!currentImportRequest) {
            return;
        }

        importCancelledByUser = true;
        currentImportRequest.abort();
    });
</script>
@endsection
