<div class="row">
	<div class="col-md-12">
		<div class="table-responsive">
			<table class="table table-condensed bg-gray">
				<thead>
					<tr class="bg-green">
						<th>SKU</th>
		                <th>@lang('business.product')</th>
		                <th>@lang('business.location')</th>
		                <th>@lang('sale.unit_price')</th>
		                @can('view_purchase_price')
		                <th>@lang('lang_v1.purchase_price')</th>
		                @endcan
		                <th>@lang('report.current_stock')</th>
		                <th>@lang('lang_v1.total_stock_price') <br><small>(@lang('lang_v1.by_purchase_price'))</small></th>
		                <th>@lang('lang_v1.total_stock_price') <br><small>(@lang('lang_v1.by_sale_price'))</small></th>
		                <th>@lang('report.total_unit_sold')</th>
		                <th>@lang('lang_v1.total_unit_transfered')</th>
		                <th>@lang('lang_v1.total_unit_adjusted')</th>
		            </tr>
	            </thead>
	            <tbody>
	            	@foreach($product_stock_details as $product)
	            		<tr>
	            			<td>{{$product->sku}}</td>
	            			<td>
	            				@php
	            				$name = $product->product;
			                    if ($product->type == 'variable') {
			                        $name .= ' - ' . $product->product_variation . '-' . $product->variation_name;
			                    }
			                    @endphp
			                    {{$name}}
	            			</td>
	            			<td>{{$product->location_name}}</td>
	            			<td>
                        		<span class="display_currency"data-currency_symbol=true >{{$product->unit_price ?? 0}}</span>
                        	</td>
	            			@can('view_purchase_price')
	            			<td>
                        		<span class="display_currency"data-currency_symbol=true >{{$product->unit_purchase_price ?? 0}}</span>
                        	</td>
	            			@endcan
	            			<td>
                        		<span data-is_quantity="true" class="display_currency"data-currency_symbol=false >{{$product->stock ?? 0}}</span>{{$product->unit}}
                        	</td>
                        	<td>
                        		<span class="display_currency"data-currency_symbol=true >{{($product->unit_purchase_price ?? 0) * ($product->stock ?? 0)}}</span>
                        	</td>
                        	<td>
                        		<span class="display_currency"data-currency_symbol=true >{{($product->unit_price ?? 0) * ($product->stock ?? 0)}}</span>
                        	</td>
                        	<td>
                        		<span data-is_quantity="true" class="display_currency"data-currency_symbol=false >{{$product->total_sold ?? 0}}</span>{{$product->unit}}
                        	</td>
                        	<td>
                        		<span data-is_quantity="true" class="display_currency"data-currency_symbol=false >{{$product->total_transfered ?? 0}}</span>{{$product->unit}}
                        	</td>
                        	<td>
                        		<span data-is_quantity="true" class="display_currency"data-currency_symbol=false >{{$product->total_adjusted ?? 0}}</span>{{$product->unit}}
                        	</td>
	            		</tr>
	            	@endforeach
	            </tbody>
	     	</table>
     	</div>
    </div>
</div>