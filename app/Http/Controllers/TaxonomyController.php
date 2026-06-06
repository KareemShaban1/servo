<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Utils\ModuleUtil;
use App\Utils\ProductUtil;
use Illuminate\Http\Request;
use Yajra\DataTables\Facades\DataTables;

class TaxonomyController extends Controller
{
    /**
     * All Utils instance.
     *
     */
    protected $moduleUtil;

    protected $productUtil;


    /**
     * Constructor
     *
     * @param ProductUtils $product
     * @return void
     */
    public function __construct(ModuleUtil $moduleUtil, ProductUtil $productUtil)
    {
        $this->moduleUtil = $moduleUtil;
        $this->productUtil = $productUtil;

    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $category_type = request()->get('type');
        if ($category_type == 'product' && !auth()->user()->can('category.view') && !auth()->user()->can('category.create')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');

            $category = Category::with(['parent_category', 'sub_categories'])->where('business_id', $business_id)
                ->where('category_type', $category_type)
                // ->where('parent_id',0)
                ->select(
                    ['name', 'short_code', 'description', 'sort_order', 'id', 'is_sub_category', 'parent_id', 'image']
                )
                ->orderBy('sort_order', 'asc')
                ->orderBy('is_sub_category', 'asc')
                ->orderBy('name', 'asc');

            return Datatables::of($category)
                ->addColumn(
                    'action',
                    '
                    <button data-href="{{action(\'TaxonomyController@edit\', [$id])}}?type=' . $category_type . '" class="btn btn-xs btn-primary edit_category_button"><i class="glyphicon glyphicon-edit"></i>  @lang("messages.edit")</button>
                        &nbsp;
                    
                        <button data-href="{{action(\'TaxonomyController@destroy\', [$id])}}" class="btn btn-xs btn-danger delete_category_button"><i class="glyphicon glyphicon-trash"></i> @lang("messages.delete")</button>
                    '
                )
                ->editColumn('name', function ($row) {
                    if ($row->parent_id != 0) {
                        return '--' . $row->name;
                    } else {
                        return $row->name;
                    }
                })
                // ->addColumn('main_category', function ($row) {
                //     // Show the parent category's name, or N/A if no parent
                //     return $row->parent_category ? $row->parent_category->name : '';
                // })
                ->addColumn('sub_categories', function ($row) {
                    if ($row->subcategories->isNotEmpty()) {
                        return $row->subcategories->pluck('name')->implode(', ');
                    }
                    return '';
                    // return __('messages.no_sub_categories');
                })
                ->addColumn('category_type', function ($row) {
                    return $row->is_sub_category === 1 ? __('lang_v1.sub_category') : __('lang_v1.main_category');
                })
                ->editColumn('image', function ($row) {
                    return '<div style="display: flex;"><img src="' . $row->image_url . '" alt="Product image" class="product-thumbnail-small"></div>';
                })
                ->editColumn('sort_order', function ($row) {
                    return (int) ($row->sort_order ?? 0);
                })
                ->removeColumn('id')
                ->removeColumn('parent_id')
                ->rawColumns(['action', 'image'])
                ->make(true);
        }

        $module_category_data = $this->moduleUtil->getTaxonomyData($category_type);

        return view('taxonomy.index')->with(compact('module_category_data', 'module_category_data'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $category_type = request()->get('type');
        if ($category_type == 'product' && !auth()->user()->can('category.view') && !auth()->user()->can('category.create')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = request()->session()->get('user.business_id');

        $module_category_data = $this->moduleUtil->getTaxonomyData($category_type);

        // $categories = Category::where('business_id', $business_id)
        //                 ->where('parent_id', 0)
        //                 ->where('category_type', $category_type)
        //                 ->select(['name', 'short_code', 'id'])
        //                 ->get();

        // $parent_categories = [];
        // if (!empty($categories)) {
        //     foreach ($categories as $category) {
        //         $parent_categories[$category->id] = $category->name;
        //     }
        // }

        $categories = Category::where('business_id', $business_id)
            ->where('category_type', $category_type)
            //   ->where('parent_id','<>',0)
            ->where('is_sub_category', 1)
            ->select(['name', 'short_code', 'id'])
            ->get();

        $sub_categories = $categories->pluck('name', 'id')->toArray();

        return view('taxonomy.create')
            ->with(compact('sub_categories', 'module_category_data', 'category_type'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    // public function store(Request $request)
    // {
    //     $category_type = request()->input('category_type');
    //     if ($category_type == 'product' && !auth()->user()->can('category.view') && !auth()->user()->can('category.create')) {
    //         abort(403, 'Unauthorized action.');
    //     }

    //     try {
    //         $input = $request->only(['name', 'short_code', 'category_type', 'description']);
    //         if (!empty($request->input('add_as_sub_cat')) &&  $request->input('add_as_sub_cat') == 1 && !empty($request->input('parent_id'))) {
    //             $input['parent_id'] = $request->input('parent_id');
    //         } else {
    //             $input['parent_id'] = 0;
    //         }
    //         $input['business_id'] = $request->session()->get('user.business_id');
    //         $input['created_by'] = $request->session()->get('user.id');

    //         $input['image'] = $this->productUtil->uploadFile($request, 'image', config('constants.product_img_path'), 'image');

    //         $category = Category::create($input);
    //         $output = ['success' => true,
    //                         'data' => $category,
    //                         'msg' => __("category.added_success")
    //                     ];
    //     } catch (\Exception $e) {
    //         \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
    //         $output = ['success' => false,
    //                         'msg' => __("messages.something_went_wrong")
    //                     ];
    //     }

    //     return $output;
    // }

    public function store(Request $request)
    {
        $category_type = request()->input('category_type');
        if ($category_type == 'product' && !auth()->user()->can('category.view') && !auth()->user()->can('category.create')) {
            abort(403, 'Unauthorized action.');
        }


        try {
            $input = $request->only(['name', 'short_code', 'is_main_category', 'category_type', 'description', 'sort_order']);
            $input['sort_order'] = (int) ($input['sort_order'] ?? 0);
            if (!empty($request->input('is_main_category')) && $request->input('is_main_category') == 1) {
                $input['is_sub_category'] = 0;
            } else {
                $input['is_sub_category'] = 1;
            }
            $input['parent_id'] = 0;
            $input['business_id'] = $request->session()->get('user.business_id');
            $input['created_by'] = $request->session()->get('user.id');
            $input['image'] = $this->productUtil->uploadFile($request, 'image', config('constants.product_img_path'), 'image');

            $category = Category::create($input);

            // Attach subcategories if provided
            if ($request->has('subcategories')) {
                $category->subcategories()->attach($request->input('subcategories'));
            }

            $output = [
                'success' => true,
                'data' => $category,
                'msg' => __("category.added_success")
            ];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());
            $output = [
                'success' => false,
                'msg' => __("messages.something_went_wrong")
            ];
        }

        return $output;
    }


    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Category  $category
     * @return \Illuminate\Http\Response
     */
    public function show(Category $category)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $category_type = request()->get('type');
        if ($category_type == 'product' && !auth()->user()->can('category.view') && !auth()->user()->can('category.create')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');
            $category = Category::where('business_id', $business_id)
                ->find($id);
                                            $module_category_data = $this->moduleUtil->getTaxonomyData($category_type);
            $parent_categories = Category::where('business_id', $business_id)
                // ->where('parent_id', 0)
                // ->where('parent_id','<>',0)
                ->where('is_sub_category', 1)
                ->where('category_type', $category_type)
                ->where('id', '!=', $id)
                ->pluck('name', 'id');
            $is_parent = false;

            if ($category->parent_id == 0) {
                $is_parent = true;
                $selected_parent = null;
            } else {
                $selected_parent = $category->parent_id;
            }

            // Fetch the subcategories via the pivot table
            $selected_subcategories = $category->subcategories->pluck('id')->toArray();


            return view('taxonomy.edit')
                ->with(compact('category', 'selected_subcategories', 'parent_categories', 'is_parent', 'selected_parent', 'module_category_data'));
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    // public function update(Request $request, $id)
    // {

    //     if (request()->ajax()) {
    //         try {
    //             $input = $request->only(['name', 'description']);
    //             $business_id = $request->session()->get('user.business_id');

    //             $category = Category::where('business_id', $business_id)->findOrFail($id);
    //             $category->name = $input['name'];
    //             $category->description = $input['description'];
    //             $category->short_code = $request->input('short_code');

    //             if (!empty($request->input('add_as_sub_cat')) &&  $request->input('add_as_sub_cat') == 1 && !empty($request->input('parent_id'))) {
    //                 $category->parent_id = $request->input('parent_id');
    //             } else {
    //                 $category->parent_id = 0;
    //             }

    //             $file_name = $this->productUtil->uploadFile($request, 'image', config('constants.product_img_path'), 'image');
    //             if (!empty($file_name)) {

    //                 //If previous image found then remove
    //                 if (!empty($category->image_path) && file_exists($category->image_path)) {
    //                     unlink($category->image_path);
    //                 }

    //                 $category->image = $file_name;
    //                 // //If product image is updated update woocommerce media id
    //                 // if (!empty($product->woocommerce_media_id)) {
    //                 //     $category->woocommerce_media_id = null;
    //                 // }
    //             }

    //             $category->save();

    //             $output = ['success' => true,
    //                         'msg' => __("category.updated_success")
    //                         ];
    //         } catch (\Exception $e) {
    //             \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

    //             $output = ['success' => false,
    //                         'msg' => __("messages.something_went_wrong")
    //                     ];
    //         }

    //         return $output;
    //     }
    // }

    public function update(Request $request, $id)
    {
        try {
            $business_id = $request->session()->get('user.business_id');

            $category = Category::where('business_id', $business_id)->findOrFail($id);

            $sort_order = is_numeric($request->input('sort_order')) ? (int) $request->input('sort_order') : 0;

            $update_data = [
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'sort_order' => $sort_order,
                'short_code' => $request->input('short_code'),
                'is_sub_category' => !empty($request->input('is_main_category')) ? 0 : 1,
                'parent_id' => (!empty($request->input('add_as_sub_cat')) && $request->input('add_as_sub_cat') == 1 && !empty($request->input('parent_id')))
                    ? $request->input('parent_id')
                    : 0,
            ];

            $file_name = $this->productUtil->uploadFile($request, 'image', config('constants.product_img_path'), 'image');
            if (!empty($file_name)) {
                if (!empty($category->image_path) && file_exists($category->image_path)) {
                    unlink($category->image_path);
                }
                $update_data['image'] = $file_name;
            }

            $category->update($update_data);

            $subcategories = $request->input('subcategories', []);
            $category->subcategories()->sync($subcategories);

            $output = [
                'success' => true,
                'msg' => __("category.updated_success"),
                'sort_order' => $category->fresh()->sort_order,
            ];
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . " Line:" . $e->getLine() . " Message:" . $e->getMessage());

            $output = ['success' => false, 'msg' => __("messages.something_went_wrong")];
        }

        return $output;
    }


    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {

        if (request()->ajax()) {
            try {
                $business_id = request()->session()->get('user.business_id');

                $category = Category::where('business_id', $business_id)->findOrFail($id);
                $category->delete();

                $output = [
                    'success' => true,
                    'msg' => __("category.deleted_success")
                ];
            } catch (\Exception $e) {
                \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());

                $output = [
                    'success' => false,
                    'msg' => __("messages.something_went_wrong")
                ];
            }

            return $output;
        }
    }

    public function getCategoriesApi()
    {
        try {
            $api_token = request()->header('API-TOKEN');

            $api_settings = $this->moduleUtil->getApiSettings($api_token);

            $categories = Category::catAndSubCategories($api_settings->business_id);
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile() . "Line:" . $e->getLine() . "Message:" . $e->getMessage());

            return $this->respondWentWrong($e);
        }

        return $this->respond($categories);
    }


public function getTaxonomyIndexPage(Request $request)
{
    $category_type = $request->get('category_type');
    $module_category_data = $this->moduleUtil->getTaxonomyData($category_type);
    $categories = Category::where('business_id', $request->session()->get('user.business_id'))
                ->where('category_type', $category_type)
                ->orderBy('sort_order', 'asc')
                ->orderBy('name', 'asc')
                ->get();
    if ($request->ajax()) {
        return view('taxonomy.ajax_index', 
        compact('module_category_data', 'category_type','categories'));
    }

    return view('taxonomy.index', compact('module_category_data', 'category_type','categories'));
}

}