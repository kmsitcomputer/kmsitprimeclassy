<?php

return [

    'system' => [
        'validation_failed' => '提交的数据无效。',
        'unauthorized_action' => '您无权执行此操作。',
        'please_login' => '请先登录。',
        'not_found' => '未找到请求的数据。',
        'endpoint_not_found' => '未找到该接口。',
        'generic_error' => '发生错误。',
        'server_error' => '服务器发生错误。',
        'agent_not_linked' => '您的账户尚未关联任何代理商分支，请联系超级管理员。',
        'field_required' => '此字段为必填项。',
        'field_invalid' => '值无效。',
    ],

    'auth' => [
        'login_failed' => '邮箱或密码错误。',
        'register_success' => '注册成功。',
        'login_success' => '登录成功。',
        'logout_success' => '退出成功。',
    ],

    'order' => [
        'created' => '订单创建成功。',
        'status_updated' => '订单状态更新成功。',
        'cancelled' => '订单取消成功。',
        'no_agent_branch' => '该客户账户尚未关联任何代理商分支。',
        'empty_items' => '订单必须至少包含一件商品。',
        'agent_profile_incomplete' => '代理商店铺资料不完整，无法处理订单。',
        'invalid_quantity' => '商品数量无效。',
        'insufficient_stock' => '":item" 库存不足。',
        'invalid_status_transition' => '不允许将 :entity 状态从":from"更改为":to"。',
        'status_endpoint_required' => '请使用专用接口设置状态":status"。',
        'payment_not_verified' => '付款尚未核实，订单无法处理。',
        'invalid_village' => '所选村/里无效。',
    ],

    'product' => [
        'deleted' => '产品删除成功。',
        'image_deleted' => '图片删除成功。',
        'variation_deleted' => '规格删除成功。',
        'category_deleted' => '分类删除成功。',
        'variation_requires_attribute' => '规格必须至少包含一个属性-值组合。',
        'variation_not_enabled' => '产品":name"未启用规格功能（has_variations = false）。',
        'variation_required' => '产品":name"需要选择规格。',
        'stock_uses_variation' => '产品":name"含有规格 — 库存必须按规格管理，而不是在主产品上设置。',
    ],

    'fee' => [
        'updated_product' => '产品佣金更新成功。',
        'updated_variation' => '规格佣金更新成功。',
        'product_uses_variation' => '该产品使用规格 — 佣金必须按规格读取。',
        'variation_uses_product' => '产品":name"含有规格 — 请按规格设置佣金，而不是在主产品上设置。',
    ],

    'stock' => [
        'adjusted' => '库存调整成功。',
        'agent_id_required' => '超级管理员必须提供 agent_id。',
        'invalid_agent' => '提供的 agent_id 不是有效的代理商账户。',
        'negative_result' => '此次调整将使库存变为负数。',
        'insufficient_column' => '库存操作无效：:column 不足。',
    ],

    'user' => [
        'created' => '账户创建成功。',
        'role_not_authorized' => '角色":role"无权创建":target"账户。',
        'unsupported_role_combination' => '不支持的角色组合。',
        'agent_id_required_for_role' => '该角色必须填写 agent_id 字段。',
        'invalid_agent' => '提供的 agent_id 不是有效的代理商账户。',
        'invalid_korsal' => '提供的 korsal_id 不属于该代理商分支。',
        'deleted' => '账户删除成功。',
        'cannot_delete_super_admin' => '无法删除超级管理员账户。',
    ],

    'referral' => [
        'not_linked_to_agent' => '该推荐码尚未关联任何代理商分支。',
        'invalid_code' => '推荐码无效。',
        'code_not_found' => '未找到该推荐码。',
    ],

    'cms' => [
        'block_deleted' => '区块删除成功。',
        'block_activated' => '区块已启用。',
        'block_deactivated' => '区块已停用。',
        'reorder_saved' => '区块顺序保存成功。',
        'unknown_type' => '未知的区块类型":type"。',
        'type_rejects_image' => '区块类型":type"不支持图片。',
        'article_deleted' => '文章删除成功。',
        'page_deleted' => '页面删除成功。',
    ],

    'payment' => [
        'method_required' => '必须选择付款方式。',
        'invalid_method' => '付款方式无效或未启用。',
        'method_not_configured' => '付款方式":name"尚未配置。请联系超级管理员。',
        'unsupported_method_type' => '不支持的付款方式类型":type"。',
        'order_not_awaiting_proof' => '该订单未使用银行转账，或已处理完成。',
        'proof_submitted' => '转账凭证已提交，等待核实。',
        'verified' => '付款核实成功。',
        'rejected' => '付款被拒绝。',
        'already_verified' => '此核实已处理过。',
        'idempotency_key_required' => '必须提供 Idempotency-Key 请求头。',
        'order_not_cod' => '该订单不是货到付款订单。',
        'cod_status_updated' => 'COD 付款状态更新成功。',
        'cod_proof_submitted' => '货到付款凭证已提交，等待管理员确认。',
        'cod_proof_confirmed' => '货到付款确认状态更新成功。',
        'cod_proof_already_processed' => '此货到付款凭证已被处理过。',
        'cod_proof_not_found' => '此订单尚未上传货到付款凭证。',
        'gateway_request_failed' => '向支付网关":name"发出的请求失败，请重试。',
        'gateway_config_saved' => '支付网关配置保存成功。',
        'gateway_toggled' => '支付网关启用状态更新成功。',
        'gateway_environment_updated' => '支付网关环境更新成功。',
        'unsupported_gateway' => '不支持支付网关":code"。',
        'invalid_dp_amount' => '定金金额必须大于 0 且小于订单总额。',
        'not_dp_order' => '此订单不是定金（DP）订单。',
        'nothing_to_settle' => '没有需要结清的未付余额。',
        'settlement_requested' => '已创建结清请求。客户需要上传转账凭证。',
        'not_fully_paid' => '交易尚未结清。只有在交易付清后才能处理退款/追加付款。',
        'already_processed' => '此退款/追加付款已处理过。',
    ],

    'shipping' => [
        'provider_toggled' => '物流服务商启用状态更新成功。',
        'provider_config_saved' => '物流服务商配置保存成功。',
        'unsupported_provider' => '不支持物流服务商":code"。',
        'method_not_available' => '所选配送方式当前不可用。',
        'couriers_saved' => '快递设置保存成功。',
        'couriers_save_failed' => '快递设置保存失败。',
        'unsupported_couriers' => '物流服务商不支持:couriers。',
    ],

    'region' => [
        'imported' => '地区数据导入成功：:provinces 个省，:regencies 个市/县，:districts 个区，:villages 个村/里。',
        'invalid_csv' => 'CSV 文件格式无效。',
        'invalid_row' => '第 :row 行无效：:reason',
    ],

    'address' => [
        'created' => '地址保存成功。',
        'updated' => '地址更新成功。',
        'deleted' => '地址删除成功。',
    ],

    'fulfillment' => [
        'invalid_quantity' => '履行数量无效。',
        'window_closed' => '只有订单处于处理中状态时才能修改商品数量。',
        'adjusted' => '商品履行数量更新成功。',
        'rescheduled' => '商品配送日期更新成功。',
    ],

    'courier' => [
        'invalid_assignment' => '所选骑手不属于同一代理分支，或当前未启用。',
        'no_shipment' => '该订单尚无配送记录。',
        'no_profile' => '您的账户尚无骑手资料。',
        'not_your_delivery' => '该订单已分配给其他骑手。',
        'assigned' => '骑手分配成功。',
        'return_confirmed' => '退货状态更新成功。',
        'delivery_proof_required' => '骑手必须上传送货凭证才能将此订单标记为已送达。',
    ],

    'return' => [
        'item_not_delivered' => '只有商品状态为已送达后才能申请退货。',
        'invalid_quantity' => '退货数量超过可退货数量。',
        'requested' => '退货申请提交成功。',
        'reviewed' => '退货申请处理成功。',
        'already_reviewed' => '此退货申请已处理过。',
        'refund_marked' => '退款状态更新成功。',
    ],

    'media' => [
        'unknown_collection' => '未知的媒体分类。',
        'upload_failed' => '文件上传失败。',
        'invalid_type' => '不允许此文件类型或扩展名。',
        'too_large' => '文件大小超过最大限制。',
        'dimensions_too_large' => '图片尺寸超过最大限制。',
        'not_found' => '未找到媒体。',
        'deleted' => '媒体删除成功。',
        'uploaded' => '媒体上传成功。',
        'replaced' => '媒体替换成功。',
    ],

    'language' => [
        'cannot_deactivate_default' => '无法停用默认语言，请先选择其他默认语言。',
        'default_must_be_active' => '语言必须先启用才能设为默认语言。',
    ],

    'profile' => [
        'current_password_incorrect' => '当前密码不正确。',
        'password_updated' => '密码更新成功。',
        'referral_code_updated' => '推荐码更新成功。',
        'referral_code_format' => '推荐码只能包含大写字母、数字和短横线(-)。',
    ],

    'settings' => [
        'updated' => '网站设置更新成功。',
    ],

    'agent' => [
        'not_an_agent' => '所选用户不是代理商。',
        'profile_already_exists' => '该代理商已存在联系资料。',
        'profile_deleted' => '代理商联系资料删除成功。',
        'profile_updated' => '门店资料更新成功。',
    ],

    'referral' => [
        'unsupported_role' => '推荐关系重新分配仅适用于销售或消费者。',
        'invalid_target' => '重新分配目标无效，或不在同一代理分支内。',
    ],

    'install' => [
        'connection_success' => '数据库连接成功。',
        'connection_failed' => '无法连接到数据库。请检查主机、端口、用户名和密码。',
        'connection_unknown_database' => '未找到数据库。请确认数据库名称正确且已在服务器上创建。',
        'database_saved' => '数据库配置已成功保存。',
        'app_configured' => '应用程序配置已成功保存。',
        'finalized' => '完成化已完成，应用程序缓存已优化。',
        'cannot_lock_yet' => '尚无法锁定——超级管理员账户尚未创建。',
        'locked' => '安装已成功锁定。安装程序页面将无法再次访问。',

        'already_installed' => '应用程序已安装。',
        'migration_success' => '数据库迁移已成功执行。',
        'migrate_first' => '请先运行数据库迁移。',
        'admin_already_exists' => '超级管理员账户已存在。',
        'admin_created' => '超级管理员账户已创建。安装完成。',
    ],

];
