@extends('layouts.app')
@section('title', __('lang_v1.closing_stock_details_report'))

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>{{ __('lang_v1.closing_stock_details_report') }}</h1>
</section>

<!-- Main content -->
<section class="content">
    <div class="row">
        <div class="col-md-12">
            @component('components.filters', ['title' => __('report.filters')])
              {!! Form::open(['url' => action('ReportController@getClosingStockDetails'), 'method' => 'get', 'id' => 'closing_stock_details_filter_form' ]) !!}
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('location_id',  __('purchase.business_location') . ':') !!}
                        {!! Form::select('location_id', $business_locations, null, ['class' => 'form-control select2', 'style' => 'width:100%']); !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('category_id', __('category.category') . ':') !!}
                        {!! Form::select('category', $categories, null, ['placeholder' => __('messages.all'), 'class' => 'form-control select2', 'style' => 'width:100%', 'id' => 'category_id']); !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('sub_category_id', __('product.sub_category') . ':') !!}
                        {!! Form::select('sub_category', array(), null, ['placeholder' => __('messages.all'), 'class' => 'form-control select2', 'style' => 'width:100%', 'id' => 'sub_category_id']); !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('brand', __('product.brand') . ':') !!}
                        {!! Form::select('brand', $brands, null, ['placeholder' => __('messages.all'), 'class' => 'form-control select2', 'style' => 'width:100%']); !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('unit',__('product.unit') . ':') !!}
                        {!! Form::select('unit', $units, null, ['placeholder' => __('messages.all'), 'class' => 'form-control select2', 'style' => 'width:100%']); !!}
                    </div>
                </div>
                {!! Form::close() !!}
            @endcomponent
        </div>
    </div>
    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-solid'])
            <p class="text-muted">@lang('lang_v1.closing_stock_details_help')</p>
            <table class="table no-border">
                <tr>
                    <td>@lang('report.closing_stock') (@lang('lang_v1.by_purchase_price'))</td>
                    <td>@lang('report.closing_stock') (@lang('lang_v1.by_sale_price'))</td>
                    <td>@lang('lang_v1.potential_profit')</td>
                </tr>
                <tr>
                    <td><h3 id="closing_stock_by_pp" class="mb-0 mt-0"></h3></td>
                    <td><h3 id="closing_stock_by_sp" class="mb-0 mt-0"></h3></td>
                    <td><h3 id="potential_profit" class="mb-0 mt-0"></h3></td>
                </tr>
            </table>
            @endcomponent
        </div>
    </div>
    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-solid'])
            <div class="table-responsive">
                <table class="table table-bordered table-striped" id="closing_stock_details_table">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>@lang('business.product')</th>
                            <th>@lang('sale.location')</th>
                            <th>@lang('report.current_stock')</th>
                            <th>@lang('lang_v1.total_stock_price') <br><small>(@lang('lang_v1.by_purchase_price'))</small></th>
                            <th>@lang('lang_v1.total_stock_price') <br><small>(@lang('lang_v1.by_sale_price'))</small></th>
                            <th>@lang('lang_v1.potential_profit')</th>
                        </tr>
                    </thead>
                    <tfoot>
                        <tr class="bg-gray font-17 text-center footer-total">
                            <td colspan="4"><strong>@lang('sale.total'):</strong></td>
                            <td class="footer_total_stock_price"></td>
                            <td class="footer_stock_value_by_sale_price"></td>
                            <td class="footer_potential_profit"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            @endcomponent
        </div>
    </div>
</section>
<!-- /.content -->

@endsection

@section('javascript')
    <script src="{{ asset('js/report.js?v=' . $asset_v) }}"></script>
@endsection
