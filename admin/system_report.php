<?php
/**
 * WAAMINI live diagnostics (super admin only, read-only). Delete this file when the problem is fixed.
 * Shows: latest errors from storage/logs/error.log, files that are missing or differ from the deployed
 * package, PHP environment, database connection. Values that look like secrets are masked.
 */
include '../includes/auth_check.php'; // super_admin only
include '../db.php';

$root = realpath(__DIR__ . '/..');
$expected = json_decode('{".htaccess":"87e7dfd6fba2f46ff0d8e40926db0257","admin\/ads_overview.php":"8ec517a29ea11a38febf8e0d51a2413a","admin\/analytics.php":"f87517583f9ed7a6683bea6f0a29229e","admin\/announcements.php":"c5e0f47fdfdf089c9abeacb12d588b75","admin\/api_usage.php":"7265b2230c9aa877ceab286c7d7f1869","admin\/audit_log.php":"42ad14ca18d711882e671cc5459dcdff","admin\/broadcast.php":"a60f8660bc11ea7e0de25eec7ac0e14c","admin\/campaign_create.php":"cf52aff36108ce7dd9f634b1c08452f7","admin\/campaign_view.php":"a1a18195fa9d8bcf5498ea80b3b1b787","admin\/campaigns.php":"e9c737e06aee309b5d2dca82087ec96e","admin\/chat_api.php":"a61932e4db3879037e7c46e91b39816f","admin\/client_create.php":"70362f3e0e357e3b8f41281bd4fe5e65","admin\/client_view.php":"d619ed83e394cd0f3bf111d680a37993","admin\/companies.php":"689107182f79282e5742916395fab2d4","admin\/company_add.php":"2cbb5c8fc25ea8785fdc0c4f9ebed220","admin\/company_edit.php":"1ddccd46012ec17e2add70b1e2252e50","admin\/contact_add.php":"6482636e4585950d7015acf5dadd47ef","admin\/contact_edit.php":"8faa656a07795aa670f0782596add10c","admin\/contact_import.php":"62abb8cfee398d160362b06fef294650","admin\/contacts.php":"97e589087dee572d0cb04f7bbc3bb9a8","admin\/coupons.php":"dcbbc656c9ddf2feee2d82a33adb8bcf","admin\/dashboard.php":"729b0c47755e654d824df58c035c610c","admin\/email_templates.php":"d5b3bc7ec2584bc68ecb92ebe30cd243","admin\/google_setup.php":"344e684ae7d46f028fd347300ccba746","admin\/group_manage.php":"c0fdfba547c3b27fe715ddbba62e0552","admin\/groups.php":"2f782ae0f8125a5f4fa7fffc1c7de0ca","admin\/impersonate.php":"29b7617492ddf62aca020307be8bd843","admin\/new_registrations.php":"2a6b43c6533a8f84e96841aa951343bd","admin\/plan_edit.php":"0cbac9e01f61a7846301ac1b24fd4346","admin\/plans.php":"8bcb5364d46cab09e6e822d2ba525ddb","admin\/profile.php":"1dfe1a2fd755be0d30545bda8b1bb5f8","admin\/pulse.php":"ef225d8b0e32b23b4e8418b893e6eb9a","admin\/queue_health.php":"2dfc49e36d593d83f71ace379ca6decb","admin\/revenue.php":"faae66e38cffb8b5ae37436d2eb703f9","admin\/server_health.php":"3e1917f35462b8597376d04b3806474f","admin\/settings.php":"782820c5afe45458695dabdf32270353","admin\/settings_general.php":"d9948448dba0410520aab7a555418f02","admin\/settings_payment.php":"39d7ca5365a9dd1702a144b7aec71d9c","admin\/settings_smtp.php":"4f7fb9153eeafe0a9971f5165e3b2518","admin\/settings_whatsapp.php":"1fde88701ab04d15f39d3a14afabde7c","admin\/stop_impersonate.php":"8e3b71c8b0abae7410a735a91d5db660","admin\/subscription_add.php":"bb908203ebfec4be55ac489f53c5e119","admin\/subscription_view.php":"7027e5ab80c40dfd04efdcab6cc561bf","admin\/subscriptions.php":"6661f9ef859467a374943cc0ed5465c4","admin\/subscriptions_manual.php":"4effabaf09d14a67805a4a806a3d0425","admin\/support_inbox.php":"06e7e7057ff19f0642e60b532f28030c","admin\/system_health.php":"bb102e58cf490f33da57baafb2c6602f","admin\/templates.php":"62187fea54e588ec4071a005a8dce06f","admin\/user_add.php":"5246634a85c2e2a5bfa0436a6a32bceb","admin\/user_edit.php":"5058790c110e6702ef45c3ddb62c95c9","admin\/users.php":"5df25da110c818652054b97f31a45110","admin\/vendor_subscription.php":"539f5cb96ca6fb3c879870b09712db7d","admin\/waamini_bot.php":"ee6f6bf5259b718ebc0fb8f538269c9b","admin\/waamini_bot_models.php":"aa88e23497a7660500213e2e90c2ba19","admin\/whatsapp.php":"ad9d2ef424f8a11bc0e2ceb053b41af9","ai-chatbot.php":"4ca3b7bc67c63fc88aae93405ceecac3","api\/admin_new_registrations.php":"06eeb12093d47d16d8a075ae370058e6","api\/ads_media_tick.php":"142d575b653b161fcd18138d3bb85b08","api\/ai_campaign_writer.php":"32f97e34986138bd6bb3858a90f8984c","api\/ai_compose.php":"cff40a0860a3cf6e1c226ae5b9597d24","api\/ai_smart_reply.php":"849d5ae91648dd5f88be85aee2ecba17","api\/analytics_whatsapp.php":"2958470435cd7736f339f4c8f8c4b38f","api\/auth.php":"bb4439a099292c42f900cef9fc273846","api\/campaign_tick.php":"c6fb7fb0df6e5e8d8bf909d9d8c10d4b","api\/campaigns.php":"aacb4c410c119348e0020fe87417e6b6","api\/cart_event.php":"6dd6d009d796616308a2e1c6105bd686","api\/catalog_report.php":"845ea9e14788671ff3b31b19b7b0145d","api\/chatbot.php":"4ec32611763d4417bb4499d97f629f0f","api\/contact.php":"9456d7b743c33e257cec101f20223ffd","api\/contact_export.php":"f5f317004a6fbcfd6ad3d5053b5f4a1a","api\/contact_notes_add.php":"3363ae5b45a823e7bb1de7c343ebb53a","api\/contact_notes_list.php":"87379e199d1156875c72d31f2327148c","api\/contact_reminders_add.php":"3bd1c2eda1226d60ee3cab3318b55501","api\/contact_reminders_done.php":"68d51a46126cf6e64317cebc2ad198a4","api\/contact_reminders_list.php":"168844328ced7a3c5223ef4a14d9e60e","api\/crm_lead_sync.php":"103fe4648d513fa86908554de90182e4","api\/cron.php":"48235725edbccaf0f03d9872efdb7a28","api\/cron_drip.php":"3ff23548171da7664f5a1e7551601b24","api\/drip_campaign_update.php":"1fb758a2ad8148a33dee0e4fb96ccdae","api\/drip_create.php":"151eca6d3e8b0c160f29d46c18e9b167","api\/drip_step_add.php":"c492bde0dd6d4ad5b2e3918ea216c9cc","api\/drip_step_delete.php":"ffb261fcf27e84f3d11e2fee5e3ed4b8","api\/drip_tick.php":"0f9ad0f61122a3e62100ba736a11e9d1","api\/drip_toggle.php":"9efdd9be5ff64c039cda1b87c48a7fa6","api\/e2e_worker_tick.php":"a7f3d2efdf515f0670fc89f3132039ab","api\/ecommerce_catch.php":"d98dd618364f5657b73e77e9dd8d66af","api\/fb_leads_webhook.php":"c775eee61138ffb527b605c9c247254d","api\/fb_webhook.php":"1bc5d4fd0056b87162ba69a395fb62e4","api\/get_chat_messages.php":"ced3cb7875bca4fcea40f8a21bfe0e42","api\/get_sidebar_updates.php":"ce34c5c806898236bdb658eb586edcb8","api\/health.php":"f4ddb9eb9b4296a7ca58c1db13a4f47e","api\/health_queue.php":"f95ba9709e5c830324c699d8a6702f57","api\/landing\/alert-popup.php":"100c1459823ef0046755a60fd4411dab","api\/landing\/alert.php":"7f5302e4d700f368de3bade49015edf3","api\/landing\/blog.php":"d02a7863398ee27b309ad01bac765829","api\/landing\/blogs.php":"925bbbeb4396d85d0e4e7ed39ea8f237","api\/landing\/features.php":"25177a1f668e72005fe09076a9fac286","api\/landing\/hero.php":"b0b2d1e5b5d55b4ab64b33e5b878a1b9","api\/landing\/offer-counter.php":"531093cdcdf797fe345339b28ac41d89","api\/landing\/settings.php":"8efa4b2853a2f7d265fcf069f363cd35","api\/landing\/upload.php":"4911f9d67a48a438d34bfc679828ac13","api\/missed_call_trigger.php":"b026068dc6885531522f26da80842ce4","api\/missed_call_webhook.php":"29464512b8b837e0c21fb004e0e3b357","api\/ocr_process.php":"30e61a30a7d064d6df9dade974f02345","api\/ocr_save.php":"99675bd3a6733497fe299decaeb8e480","api\/payment_success.php":"25bc25dd55bf8c7d1246cc9c261645db","api\/payment_webhook.php":"95b71db016e8d5039b45ed663bb462d6","api\/pincode_count.php":"77f0e12d02e001693ed35e883920b241","api\/process_automation_queue.php":"b5a28417495f9de01270b8aa747abfbd","api\/product_enquiry.php":"9f0a63e6a9f9b31c73d514d1cd0be7ef","api\/save_contact.php":"8b74aa2b0b6fe691a2dd3587641058b2","api\/scheduled_tick.php":"b451aa1083dba82edfd06d93b0a64b5b","api\/send_gst_invoice_wa.php":"85f62ab39dce7a88c9be75725e6ba35a","api\/send_invoice.php":"f18a3a999edfcda4589575bf58587d67","api\/send_message.php":"7d5dc21041a6e4a162401fccf279f205","api\/send_payment_link.php":"f0f31851fa10cee2b1def9ba1d3afa2d","api\/settings.php":"4b2bd561a5153a2346b73e25affc8620","api\/view_quote.php":"2c4750ae00198738ac817e5d44483d3e","api\/webhook\/whatsapp.php":"6b4c8b4f723d41175e3e481a2d34ac82","api\/zapier_catch.php":"ecb1cbe2b888965b430c620a6570ee94","assets\/uploads\/.htaccess":"c1335e2afc9cb09587417ed91b1215c5","assets\/uploads\/blog\/.htaccess":"c1335e2afc9cb09587417ed91b1215c5","auth\/google-callback.php":"016925d7fcef48925d2ba35e34eea52f","auth\/google.php":"971807f338faf0ae5b9c2be25a4e418e","blogs.php":"d47d7383f66a75b408d8388645a3b9d7","client\/ab_testing.php":"2dbc42522fdf226d72396c66ba56c7fa","client\/abandoned_cart.php":"748f35f58a1a7e2146f237a344e8f9e5","client\/ad_media.php":"f7dbb48b2dce6e7bad442942d1b6b9a4","client\/addon_packs.php":"5b185e7d7f9a167bc3229b5f6f523cd5","client\/ads_capi.php":"7ca78be3a40e4f5bc88f227909192af4","client\/ads_leads.php":"634df8521f22ced195f36a086c8f69c8","client\/ai_campaign_writer.php":"0337dcb1f9e6dde87827dc088762b6c8","client\/ai_lead_scores.php":"e2a574f212492c3a84d38f531241a49e","client\/ajax_ai_lead_analysis.php":"4382c1d7536b90293a2fb3882ff50046","client\/ajax_load_chat.php":"5260e5b0b00e6be8996acc3983362f19","client\/ajax_mark_upi_paid.php":"9205b19098fcb4a3033fb9f6d7338c18","client\/ajax_media_proxy.php":"824e61851df2228d451be2ff67d86770","client\/ajax_run_processor.php":"df1884f2f050f009cc6e0249ae059823","client\/ajax_send_order_payment.php":"3d70b1b7a8320983f74fe3d3daa85940","client\/ajax_send_payment.php":"3bb7877c49c673b9828d5b6ec5cfb8ac","client\/ajax_send_quote.php":"79be8e8fc751f4544c70a4720e5a2bde","client\/ajax_templates.php":"77a6047ff5890af4aa896d831be0af0e","client\/ajax_update_gst_invoice.php":"a45658832c5f7ff099d6ef119369dce8","client\/ajax_voice_transcribe.php":"a5e9add30232e266eaf7485b8abae497","client\/ajax_waamini_bot.php":"061fa8c967ba8783484c5dbc6b735336","client\/analytics_agents.php":"aa3b656cbb2cdfb6bbc33230d9bc0da4","client\/analytics_revenue.php":"3aaee60249b4c93a6009c9c04c5363da","client\/api_save_chatbot.php":"63e19b580c281b4d989cc5060db1d57e","client\/automations.php":"68c22259af3f1e977ef13edf9c1b9d28","client\/billing.php":"b1c304be94e99c16a3cabfcc85305d27","client\/blacklist.php":"92f6f9b3c0dc4d76547dd1d95062953c","client\/bookings.php":"d38413484edc7e4efe16f71375b95974","client\/bot_replies.php":"341bda352991d79ee74a35a475350243","client\/brochure_sender.php":"970f4e30683ad464a3f938ec322678a6","client\/campaign_create.php":"9a3598a5c24e33819e778125f4ae0ff3","client\/campaign_reports.php":"159ac1b194ccce40059fc18847463ad4","client\/campaign_view.php":"35ee83ffa02373700d4b9b659f15ec69","client\/campaigns.php":"ee4bc6227d3cdfddbd2cdc207f6d4799","client\/catalog.php":"f19f4a9e1367bcd98b63bc4ff87bad33","client\/catalog_reports.php":"09a97969b4c07a59abe0d5b549b30c08","client\/chatbot_builder.php":"ab0f17bc6dfb2c666eb2b61849312e82","client\/chatbot_flows.php":"4960a0d2bfa9d80332b5eda80fbfe6b1","client\/chatbot_simple_builder.php":"3af898899cb5162b6ad8cd79dc754359","client\/contact_add.php":"1175898681ee6feeacbf994c4822bae7","client\/contact_bulk.php":"d10ac3f95833dbf18ef706b2cdcd9d8b","client\/contact_edit.php":"475bd4c7fd82d9c40eef2609d0947adb","client\/contact_groups.php":"f990f1676b8c231a05d087fbbbdf64c4","client\/contact_import.php":"6c4636d9d3e70d932445f533be3e4b00","client\/contacts.php":"6f4207908abbe2993434d06ac3e8cda2","client\/dashboard.php":"5dbdc5a365577723961fda8fed64dde4","client\/dashboard_trend.php":"8b52d31436bcb578350dc09430b2f67a","client\/delivery_trend.php":"7cb98bd92ea44c9e2cf8dac72fd289d7","client\/demo_bookings.php":"af3c0d21617a0e8b744d683c68a181d3","client\/digital_vcard.php":"d3569ea1ebb1e04fc41fb806ea03c36c","client\/drip_campaigns.php":"984a826ccd1384d42dcc4dfb47d8dde2","client\/ecommerce_settings.php":"be7a988950bc979714380aafcc6e3940","client\/festival_calendar.php":"fb4917cfde0b2e2b7042f916ad10315f","client\/festival_campaigns.php":"b6d66688618a885b7f4dfb486dbfa609","client\/flow_api.php":"56d874a9cdd0e1d043476b50311ccbfc","client\/flow_builder.php":"7c0c23eb8167b6fe3e80d2e6a6765112","client\/flow_upload.php":"933a585cc33a29d710d583ecfdc07e84","client\/form_builder.php":"ff5fec5c6996c624b2e53f4d8e686943","client\/form_responses.php":"281b941c0ada6e24f0bb4c9281e8271d","client\/google_calendar.php":"bdfe3b2c0d8462d9acb8969af906f5ee","client\/google_calendar_connect.php":"01ce06787e712fcf906860176c725bef","client\/growth.php":"cbf548d7b35be1a653189515dd54a732","client\/gst_invoice_create.php":"10de1ecb26f4df6d907fe7a96beb8710","client\/gst_invoice_view.php":"dc2eb84ca96707b8d9b4b7e104e6afd7","client\/gst_invoices.php":"b6859f69f5320758cc46e4c62e3d3496","client\/gst_settings.php":"7789dfba6151b8ec40eb5c07c5ff0dde","client\/industry_bots.php":"de5bf959f63be4901f71b377e28ac668","client\/integrations.php":"22a3b42b413c13e3ed22acd7d7596e1b","client\/lead_automation.php":"9538ccc0a0c1fcb49997b70a2434a002","client\/live_chat.php":"deecdd8ef5e1300477697e5516822955","client\/manual.php":"b865bebfcc48de2aa0a378ea09dfa6bb","client\/medicine_reminder.php":"4b93f7de90933bc5095bf3d51818c39f","client\/medicine_reminders.php":"cf4be0e6a25f7d37e582e059b324e787","client\/message_log.php":"006374eb2bfc237599276b7d2b64168f","client\/missed_call.php":"49afcb7c14927b84bac016aa4848c71f","client\/notifications.php":"0e13cba8a675f4d87578bbe595deff1a","client\/ocr.php":"f757613911a02125aac291bb02335575","client\/order_dashboard.php":"155ec8c74c4f4c901f4fda02735eb8d9","client\/orders.php":"bb450a790e565bb07e099e0dfb4c509b","client\/pdf_settings.php":"4ae9f1664f6bb15b0e001f8b7e7d429d","client\/pincode_campaign.php":"9106c9ade0e1dd921b191a82c7d99e1d","client\/polls.php":"12c77cb7082150cab3de7a8d973d5736","client\/premium_abandoned_cart.php":"6328b0316355b46379d4e0b339e13bb7","client\/premium_ai_bot.php":"45738874ab4ade2083565dbe1a6b3bbb","client\/premium_analytics.php":"3d3216e0fcc77242436a67709c9ac061","client\/premium_drip.php":"73270943e548a2500f00b2689b3db9c3","client\/premium_drip_builder.php":"28a23d376c12c553d686bd1096bd6f18","client\/premium_features.php":"15756a51cff51478afa5919556c0396b","client\/premium_flow_responses.php":"7b075b24c5d3383cb704c44c566c61a7","client\/premium_flows.php":"6e43d7f8cd7e8daec7f2856c9900aec3","client\/premium_loyalty.php":"a7ced2a50868a554a8ecd78349657928","client\/premium_ocr.php":"3a9e19d3c5eec9f54f24729150c302c4","client\/premium_voice.php":"48b895836c4461e00a766c7d2794fbf2","client\/premium_zapier.php":"3a5c169a2ec2cd0e3f0fb131eb03d9e9","client\/profile.php":"a63b11af7a76e3f045a8cb25d709aa8b","client\/pulse.php":"104b5f5a19cca33af3152e733571d7f5","client\/qr_code.php":"dc78d6d12edd73e415ced98efa2c5bd3","client\/quick_broadcast.php":"9efe725947ba7acfb2a33e1ad888d615","client\/quick_replies.php":"0581d0028ef9477dc4d5b034c5e8a151","client\/referrals.php":"7d4396b872e2c4033da16c8da368f8ab","client\/review_bot.php":"21d89253b4b77edc85ef62c1f36bb750","client\/scheduled_messages.php":"65e8ae38fbaf749f318e418346e1b370","client\/school_fee_settings.php":"fcf74f0a720e708f9c527628f0875029","client\/school_fees.php":"a609f6802a1dd139aa225aa6a6f6fcad","client\/security.php":"921eaf86900fecafff9145597685bf81","client\/settings_api.php":"4c81381e4656cbe1deb4120b2df18bde","client\/settings_birthday.php":"4ee9b7bd653e0644f59d87f827db81ba","client\/settings_booking.php":"f2df0f6c788c279b5827e89ac59b68e2","client\/settings_bot.php":"45738874ab4ade2083565dbe1a6b3bbb","client\/settings_cart_recovery.php":"1e34a897dbb798751808e64fbb38251e","client\/settings_emi_bot.php":"a439a5aeeff3530101e88ba9d41b63b8","client\/settings_fb_leads.php":"db64d4ccf3744d8fd93ee9a0812fef89","client\/settings_general.php":"fca5c462e949dc85a821afd79e9d3931","client\/settings_gsheets.php":"ad5a4ce6745bc8b8060cad80aa0d859e","client\/settings_gst.php":"7e68faa2d05dbca68c270a78751ac1d9","client\/settings_indiamart.php":"4a8cb5552c5f8e7ff2664fbfe82a77f3","client\/settings_missed_call.php":"1df72ce1286be3912a1cb90076834cb1","client\/settings_order_tracking.php":"de794716f24adbb3154ab27e13bce44d","client\/settings_payment.php":"ab3c5459df39e478d456ab08ddd92ab6","client\/settings_pdf.php":"f43757a384841f1b0b7ebde7e84b6ca1","client\/settings_review_bot.php":"3532fc7b4fc9b6cc7ad7f0f77e7ab23d","client\/settings_sales_tracker.php":"7a0066c10e41c44c7c6702d3bff2bcc0","client\/settings_school_fees.php":"ddca53de9698e6aea0a7d54cbc2ec8ce","client\/settings_spam.php":"13d93a3fe0f5d4acd1224b9d1981eeac","client\/settings_upsell.php":"ab717a2f71ea5c7a84f529c1e20a9c7f","client\/settings_vcard.php":"209fc5fe2c828914925c96c347918615","client\/settings_whatsapp.php":"76bbe1ccaeb43cfc208f678e5aee5407","client\/settings_zapier.php":"bf7a78de268d6495b36244d8431f1a86","client\/spam_protect.php":"feb3b3375b5a052c98bd75997f13fef5","client\/stock_alerts.php":"5eb2888fc6d1ce9c175f866ced4090cd","client\/subscription.php":"5dd9bb7c396232696d28ee30d98400cb","client\/support.php":"cc7725f944cc25941f568284a4da08f7","client\/team_members.php":"12195beb5431ed1ef9efaf964f38643d","client\/template_create.php":"cd3e6184b9ed558d64d9d7575f69f9cf","client\/templates.php":"b79e582f3e68d45b371d3b4ae13fed69","client\/usage_trend.php":"b0186269102b31eacbac24d1bbe0cb53","client\/user_guide_data.php":"2990373bdca448c487011136477a56d7","client\/vcard.php":"e5aa0ad4f5433d6d40638885142d2e99","client\/vcard_settings.php":"3770d8988cddab698ca6ed1fd7b69222","client\/vehicle_reminder.php":"a2192b00fd42a73fafb1da13716e88c0","client\/vehicle_reminders.php":"a8320d4462c157c9a3de5b5ef3c363f9","client\/wa_health.php":"8eb6b855a89ece2f50df0c48f57e0587","client\/wa_health_monitor.php":"d3a1e0704681d7b442b1cc576dd33b91","client\/wa_polls.php":"5aafa07df9008b4be4faae72a386b921","client\/waamini_bot.php":"119e76944386d9b93f53866a66b4a377","client\/waamini_bot_knowledge.php":"97b5b681cf3e8d621ee3287731f419b7","client\/waamini_bot_settings.php":"688e6ccc3955f0b0657b06121e3e6b1d","client\/whatsapp.php":"f57d1cfe94e966889901fe4e2895fb87","client\/whatsapp_health.php":"76cb46f18ebffdcb36fbd12e2a9410a4","config\/bootstrap.php":"ee32550d51f53a4eb81b5347c0c628f9","config\/config.php":"8067910be48df29e5c46604407cec613","config\/database.php":"8960c84f1e7a5c74737850debc5c503f","config\/env.php":"ed5e3dc7e2654336ba216b01d94db992","config\/meta.php":"612b5fe75527e68f0bd2bdc322a5e647","config\/whatsapp.php":"9b635a7d854b281a962bc9787234f766","cron_automation_sender.php":"b3c7d4a82c0a802acc8ca775063ddd4a","cron_birthday_sender.php":"57fa5ec05eb73e6fa2cf926021f131cd","cron_campaign_sender.php":"1a6cbdeeeb7da684903a70341c6c2b06","cron_cart_recovery.php":"74f703b6791d274f01d8ebcff3fff1c4","cron_demo_rescheduler.php":"4e4f22e370c56544a3e6544271618f2a","cron_eod_sheets.php":"95083c2c3872b72e3453845249c7268a","cron_fee_reminder.php":"2860c26514094981b25ccc4b00014da8","cron_festival.php":"f063ddc067f2ea006b45c9fb599935dd","cron_festival_sender.php":"85f30529e81f5fd4383b35d0a25b0e56","cron_flow_tick.php":"f16ca84041ab7df6070f61bf56771170","cron_flow_worker.php":"ecb4f77c8a744d0d11b1ece966f79845","cron_indiamart_sync.php":"a5767560e95245f475eec8bf21b73415","cron_medicine_reminder.php":"1afdc83c7c70461d61a77c7badfec442","cron_meetup_notifier.php":"49e9e378b203024e211e7893af0de7fc","cron_pulse_analyzer.php":"de345c1edd94fdbb05a7ea0cdeb809b0","cron_pulse_watchdog.php":"17a90ac0426759c6cf15d1c9ae0e6f6c","cron_review_request.php":"d77e94d93044704b4be16398d698be78","cron_school_fee_reminder.php":"4db360ea1195fa499e7fdc22030e752a","cron_subscription_alerts.php":"69eaa17925d190cbf80976e90ce741fb","cron_subscription_reminder.php":"6c832ddfa8924728269c11e537d618e0","cron_trial_nudge.php":"d1c83b5e3d01bcc7ff01b63682b1552d","cron_vehicle_reminder.php":"3bf2d4781eeae1f874acb953101858a0","cron_winback.php":"5282f40c35347abd6c29c49ca2a7c468","dashboard.php":"d9104b34f1ab3922c5d4b9b8b08db023","data-deletion.php":"576806cd4dd549335261f6299b03210a","db.php":"c5f27b2b35cb080dd5715e3ee39b0870","ecommerce.php":"7d67f498ea76c519ed0ddf120538071e","education.php":"b5a4d0dd8c68f62e596c1051a7782b9f","features.php":"f3b037b7a750d4110b9cadf0921d9370","forgot-password.php":"f69a3eba335d89fecfda1ebed609aaac","gst-invoicing.php":"8c38f2d2ddf334b34b69ff9003600ffa","healthcare.php":"58bc4e41281949dd0d0675bad1773706","includes\/CampaignProcessor.php":"5c36cc961121cc03858160963fc27f13","includes\/GoogleAuthenticator.php":"7145948688c5fd99bdc21da9203103c3","includes\/admin_footer.php":"bc7403ca7549e58b65e04b0623ca4984","includes\/admin_header.php":"0aff163fee4e96dfbd47b85a8a2c1537","includes\/ads\/helpers.php":"ff562771c12246ccfb27fedacbc79c1b","includes\/ads\/schema.php":"9eed0ea6583a9fa153c106e956863cec","includes\/ai_crm_sync.php":"0ddc3145a0b530a14bb2d07ef7742434","includes\/ai_language_reply.php":"48ec23b304bc3256e142f4668179d53e","includes\/ai_lead_scorer.php":"dc55a7e3ca8d830f91990810bb4bd3c6","includes\/ai_usage.php":"64b2f167ebed6d36645e5cdb3e442b30","includes\/ai_voice_handler.php":"1030e78454a82b2d58ac41919713597d","includes\/alert_bar.php":"1c6f90815e67ee6930da94e9b1dbc0e3","includes\/alert_popup.php":"98fdc30185dae6caf43e61ddf80e1048","includes\/audit_helper.php":"3740a1e6514bc073aa78aebb46e598e6","includes\/auth.php":"0ed25089e3a78b8fb6997dbc6829852c","includes\/auth_check.php":"a7b7477d371d793795577827002ba939","includes\/auth_layout.php":"c077ee6164892119f8c326dcdb6344b1","includes\/automation_hub.php":"361663f78b1eb2a53dd851f570d16ce3","includes\/booking_bot_handler.php":"75f929d9e4fb6600c0b24c4cc009c961","includes\/bot_triggers.php":"27917e5a840eb77dff903d6c91aa04a9","includes\/broadcast_hub.php":"2cf34259ae2302e0799ded62bef3471a","includes\/cache.php":"8b84570f36eb53476af2efff686367b9","includes\/campaign_trigger_helper.php":"1129ef911bdbaa8c0549d364b56ee5a3","includes\/chat_contact_sync.php":"b831b9c1ce2511ace9761441b7d8e92f","includes\/chat_followup.php":"c3b87843876ae660b393a02dc415ef0f","includes\/chat_message_render.php":"a938bbea24180515cde83d852e4489f8","includes\/chatbot_engine.php":"cd8c0bf6f5da1d092d488120266740dd","includes\/client_auth_check.php":"75e0e952f26719b6c0042e197ce2a4b0","includes\/client_footer.php":"5d3ab2faffacae486f52ac9d67932ce1","includes\/client_header.php":"91ce5a7e6baee7babcd9817ba89a3f45","includes\/cms.php":"65b08d4cf7de2b6d825308ef01fa979b","includes\/cms_defaults.php":"13ba5489a45e064bf66751fe1b96be08","includes\/cms_pricing.php":"c1fc13f3b248a639c2a923f5f878f46e","includes\/cron_guard.php":"10ad1c654cd0e0ea05e27784ea9ec8db","includes\/cron_order_recovery.php":"a029aab01ac6ac819f3082b9dc87de85","includes\/demo_wa.php":"6c332b43a27e17ce62876ae80e083dfe","includes\/drip_champion_schema.php":"f8ab7d1104f39834c7c9debd089212c1","includes\/drip_engine.php":"93cc2319143b56c73b826274d7c2dd79","includes\/drip_interval_engine.php":"04b145db3e904d5fc63992ea8ed236a9","includes\/drip_message_variator.php":"b9aea19385fb72da728c1cf2d4b4a973","includes\/ecommerce_handler.php":"aa6fd00ff324c6431222c1f3351da7b5","includes\/feature_check.php":"e8db08ae53bf0d795dd5bb11f52a9a9b","includes\/feature_page.php":"807ddd0a67eeec0d3c17cd254628ffa2","includes\/feature_pages_registry.php":"5be3f729aa6aa1a67f5a5518087b41fb","includes\/festival_engine.php":"d200688d32f33058fa5689f16d18ecfe","includes\/flow\/botreply.php":"2077b1d86ad3c0b780a78b6089c37869","includes\/flow\/engine.php":"4eeef40ceba02eb19140904ca19b17c8","includes\/flow\/nodes.php":"cc3ce4f0dae1ad4f24e78f92299433bb","includes\/flow\/schema.php":"de3119f138be8e6b3c85a21312a25328","includes\/flow\/sender.php":"c8d39fb6c43adf64cc34d6b28a5a9a92","includes\/flow\/store.php":"2035db7065944d9b19783b21640d48be","includes\/flow\/worker.php":"1e34728720ee77ff46bb03cb84764f72","includes\/footer.php":"68f0a7ac9c5c2d5624faf8f9d96c07ab","includes\/functions.php":"414d3163a194ff13f4a732868fb96442","includes\/google_meet_helper.php":"cb170805381c83fbf8b52129698dd233","includes\/header.php":"e8907010f33bf253e4ff71a77a8f2bf1","includes\/logging.php":"51da4e823379f18bb19b3ed89130c23a","includes\/loyalty_handler.php":"d532e318e5be7d3dca6d3d3ebcf7773f","includes\/lp_scripts.php":"adde1bb28a88021a67c13271b916e66f","includes\/mail_settings.php":"e9e9eb5db8a5d4ef77b72f79f184949e","includes\/mail_template.php":"6c2491fc417d3f4de33b73e421db9220","includes\/monitor_handler.php":"2d5f7a91285e76f22b262ebbeb6f4917","includes\/occasion_engine.php":"f4e2ec2edff6ef6be45855e1b0c4ef54","includes\/offer_counter.php":"9db380ff1a17b2abf8e1325f4b229a46","includes\/order_invoice_whatsapp.php":"b5b73bc28df2861a4408b3c593995af4","includes\/order_tracking_handler.php":"622a406f88ff0fbd99aacaa97aad9daa","includes\/page_cache.php":"0d9138238211d70785a20ed61c69e14e","includes\/phone_helper.php":"70e2154c449bf595341072380eaad671","includes\/public_url.php":"27df73011c895e2bc6105c39d1f71a28","includes\/pulse_monitor.php":"a776038091da2fa4c611b59aa9d121f0","includes\/ratelimiter.php":"d1d6dfa6f20163789e3fed1ab976378d","includes\/sales_tracker.php":"09bdb937db3683b2eb7eaed3766e6155","includes\/scheduled_sender.php":"f782aea44147dcd69efb7d9dc69f8e4d","includes\/schema_guard.php":"71c42a1edd44aa58d1280197483d6127","includes\/school_fee_engine.php":"6be174704c9b451eaa827808cd82acc9","includes\/security.php":"e68ebe051ae3713748a42ed4c89f4b6b","includes\/server_health.php":"e8102219b8e6652b1ab33ff8b0f77693","includes\/smart_router.php":"5161c570a3e238fff5e2022ece29169e","includes\/tamil_calendar.php":"1cf77d008ecca25f57454ef613e194e7","includes\/upsell_handler.php":"83c9362586cd8d5310b344f15e9c346d","includes\/voice_ai.php":"44c326cabac1f1ae5b1010309debc9d6","includes\/voice_transcriber.php":"96b4e4f4bee88ffc0634829b22a493b7","includes\/wa_chat_helpers.php":"698c80fce3dd395cd47cb339ab36048b","includes\/wa_connection_notice.php":"b3e5eda82b0ae75ea489aab940d3de78","includes\/wa_flows.php":"36748499331016f0141862e1e0e0fd97","includes\/wa_rate_limiter.php":"56d5bcf59d14245d21a34992bfe86f68","includes\/waamini_bot\/bootstrap.php":"43fe345c7ac63362c68b4d501581d2a0","includes\/waamini_bot\/engine.php":"28b54291c96a2b44d0f5e4b2f6a0cb4a","includes\/waamini_bot\/guard.php":"de80802ddd7ba238de0bbd6e0095ab77","includes\/waamini_bot\/knowledge.php":"fc9c6e60d2726af51a03307ef349924f","includes\/waamini_bot\/openai.php":"f3df60787badd476df4f79ef94af113b","includes\/waamini_bot\/store.php":"b6e8a421b2823e68ab31e9cbfc61e355","includes\/whatsapp_api_helper.php":"b92c58e39e5d7d94da2b4fbb988969d0","includes\/whatsapp_catalog_checkout.php":"97d6ad0bdae8958bcdd94b97e47014d1","includes\/whatsapp_settings.php":"21e3e62eb5b0a01c2393c15e96e864eb","includes\/whatsappapi.php":"12af205cebea44d4491d702baffcc81b","includes\/xlsx_writer.php":"7ed2abe6c5b1fa1c2f0b0dd8f40ea180","index.php":"89bc00bb0798b710fbc144d8b984f6b8","landing-admin\/alert-popup.php":"c11e680c66959e77d4eb5ce6527e918f","landing-admin\/alert.php":"4a4b01764cf38f2ce0454aa491a8411f","landing-admin\/blog-edit.php":"01d1eb3741c0b3b0055d7e0a63b2af0a","landing-admin\/blogs.php":"8aaa9e4c16d1f1b290c9d1b779c3e12c","landing-admin\/categories.php":"b1a7c45d2baae7d0b61f35f1cda3b166","landing-admin\/features.php":"68e38ec35ee4fe1817c31fb652bcf470","landing-admin\/hero.php":"48f6ef9bcbfc6828008de883e85bc46d","landing-admin\/home-sections.php":"1f2c2725a7289814370d4a4d4b7ed366","landing-admin\/includes\/auth.php":"0ba7f5921663238bd3cbc5070857f719","landing-admin\/includes\/db.php":"7ab9909a84eb90bcd33cd4b2353d1887","landing-admin\/includes\/footer.php":"a154e063a3cebe0e0db503d330e7abd8","landing-admin\/includes\/functions.php":"f2e648ff5ed390bc76811a0f23c9696f","landing-admin\/includes\/header.php":"c83e5304cce266397564b6e4a6fd99d8","landing-admin\/includes\/sync.php":"0a3bd866cb91915f85c286bea28e5962","landing-admin\/index.php":"a9461ba86b2118f8bcdc7ec751a5ee7b","landing-admin\/login.php":"8c6e9c2093edfd9c0af4bb96b02a6aee","landing-admin\/logout.php":"5350f94a7fa812266a530e33608d5f13","landing-admin\/media.php":"8c565b8a6381437d462f9adace63fdec","landing-admin\/offer-counter.php":"fc23e77518d97e46ae0e82dcb2c77d2d","landing-admin\/pricing.php":"43bededd963e2b94ba2480aabe4799f7","landing-admin\/settings.php":"4bc35ad4fec4e19c143002c99384ab1d","landing-admin\/testimonials.php":"6385d1c059a8081fe01e6523c587ed72","login.php":"c76d51d47bc8f938acb520d623ce490c","logout.php":"3491c938072348eae1a5ec696b6eab31","marketing-automation.php":"567caed999c2ffeef6503e1f2eb22d83","migrations\/fix_smart_router_priority.php":"6399c1ddf9269a28850ba997e2a9f653","migrations\/migrate_flow_builder.php":"2e5e72bd594468461649d126454b02e9","migrations\/migrate_smart_router.php":"7300f262215f9fa6d2ab71736a9eaa26","pay_upi.php":"132ee2ebf62dc14a2c156922ee49b6d2","pricing.php":"cfd276143b43549f34809e0e5d02b685","privacy-policy.php":"be071a317417960bfca181223f4c95e7","process_ai_queue.php":"1576d68db333ea26c776a52b74ce6b1a","process_waamini_bot.php":"2d9a09389b1df9c13715c750715f6913","queue\/daemon.php":"28835bab9eff44a5fa07a187c366168a","queue\/worker.php":"197ddd9cf69d75216edc62248900bdea","real-estate.php":"9d8c906de30eae24003fadd5e0cf9cec","register.php":"7faefe1c55cc2a371cbde7938c521ac8","reset-password.php":"21b84014df7e2204d6a04efc0c733aee","send_demo_reminders.php":"982022f85dbf27af2149a2e2d2c2c3c0","send_drip_queue.php":"5d76916e54b204eb4d0fb8e37ce15e63","services\/AttributionService.php":"c32640a4715f106127e3eee04dc59aa1","services\/CapiService.php":"19e03f5553a140c58817a636e25d0aa5","services\/DripOptimizerService.php":"3b1452220837b31a113ba1e2223a0d84","services\/EncryptionService.php":"f715c0831b6933faa0135b40155c74c3","services\/MediaVaultService.php":"6f92dd0a0beb8f66fcaaf6c0025d823d","services\/MetaGraphClient.php":"6c19b1d503bf1a8fb0a76346984f8cf1","services\/MetaTokenVault.php":"6973b7e8cbc1939356014ebc755e7d07","services\/QueueService.php":"76ac87fc9743d3de78ad38c8598e0ab4","services\/QueueWorker.php":"150531a3c096b2c277887be2b98a906e","services\/RedisQueueBridge.php":"50deab8c6c95c761756d95ac80a3c8cd","services\/WhatsAppService.php":"d3961198c6b91cebd16cfadd3a324d3d","smart-reminders.php":"d2f2d9344361184e02fbfadcf97a982d","smtp.php":"fd1f154ad0a54fba92ea6d02609a3141","storage\/.htaccess":"e385701297f7479d8cbd0f13f5c85dc9","storage\/products\/.htaccess":"d2ff753c9c35bf535ae7ee975df8e87b","storage\/welcome\/.htaccess":"d2ff753c9c35bf535ae7ee975df8e87b","support.php":"a093987ad66e62c7688da051400f9f74","terms-of-service.php":"0fcb099ef0547c309e21d2f347ea9185","uploads\/.htaccess":"c1335e2afc9cb09587417ed91b1215c5","uploads\/flows\/.htaccess":"1f43584f8825a1b12d9d2a7b6793c277","vcard.php":"83a17edb97d1dd9edef2d94b69451c2a","verify_2fa.php":"4babecbd41408b8c4088d9e393e3f743","waamini_ai_agent.php":"725c81f82dbd2759a3e6a555bde85811","webhook.php":"f01afc5f3a6fa3ffbb855d71f322407b","whatsapp-broadcast.php":"327d102216a20944565f97402859666a","whatsapp-commerce.php":"186343e251986055b079fe1a136ecc12","whatsapp-crm.php":"4d92d93fe972d584309f7799cff25bac","workers\/automation_queue_daemon.php":"125a3a1ffb1bcab0046570c5fdcd093f","workers\/automation_queue_worker.php":"7a607eb190c62ef429ce3a2dedad4757","workers\/redis_queue_bridge_daemon.php":"52e6fa230d8f8a63247e8535ff7cdb31","assets\/css\/sidebar-pro.css":"06d20b03b6c0289d8b8c7fe58a2e5f99","assets\/css\/flow-canvas.css":"7f7bb0b2f6566ecb3bf65bf19e3a2153","assets\/css\/mobile.css":"fb9f032f182e6887928407746ee64f6d","assets\/css\/section-ui.css":"57b4e64e7edada8d7c8b345ffcc69f36","assets\/js\/waamini-motion.js":"f964033382e46838b74f884596be0fdb","assets\/js\/flow-canvas.js":"7fe82f5a7e3393512e355f241fd9b1d7","includes\/cron_runlog.php":"5f07757969c5244acf6ee66eb9b7122c","assets\/js\/table-cards.js":"765d50fd6a7c61c3761314359fd4f2bd","assets\/js\/mobile-nav.js":"3a8cb22958b2912d3807ef7230f0d67e","includes\/mobile_tabbar.php":"87c10de16da5c55747e2c4b91e427ac7","api\/support_contact.php":"9e51aad05eeb07792e66b8ee8e089940","assets\/js\/lp-theme.js":"93b6e101c4fe5d6d7a7b23c750015ca2","includes\/shopify.php":"ef4f112605ecd21149dc2a8289668715","api\/shopify_webhook.php":"f4865fef15775614200a736125c6fdaf","client\/shopify_connect.php":"0d088f7c9a27ad3776ef947dd5bcc0ce","client\/shopify_callback.php":"80145c0fe9beebbd7dbdfd7ff00d208f","cron_shopify.php":"f0db275f072c91a22361c08e5bb20359","admin\/system_report.php":"e98613dc955f0b3f0c09e9f8d34b82d2","admin\/settings_shopify.php":"91120fef8c6b9d6e3284433b941c4435","assets\/img\/waamini-logo.png":"5b51a0a6596a48b4573bf2884b6a4e27","assets\/css\/features-hub.css":"aa95b9235661cfa576b9c4740c6e8abd","assets\/img\/waamini-icon.png":"45426a4debda389ecd7ca846352362d3","assets\/img\/waamini-mark.png":"06073dffcf1e07dc4f3673eddbe186b0","favicon.ico":"40e69899779ee3344b79724f50940257","assets\/img\/favicon-32.png":"32b9e8df35502377996a9d1371f7f52e","assets\/img\/favicon-192.png":"a65f00598d4ff3edacedb750121e8278","assets\/img\/apple-touch-icon.png":"4a1b8d34eac30fcb092dbdd246d3ff97","assets\/css\/wa-showcase.css":"82ef5afb26603cfd6d68cfc4c778a8f6","assets\/css\/feature-pages.css":"e8f4f86ed33efaee708f765407ea7393","assets\/css\/admin.css":"8079ea0319355732f68435dc0588d995","assets\/css\/client.css":"7ada218b34e84f86d415196287796943","assets\/js\/sidebar-collapse.js":"2a76980a166d66a90379ce1093397fc4","assets\/css\/auth-split.css":"7a4686260b27e80d3b358889d9a4b647","assets\/css\/ind-wa.css":"5899c08b0f42bd57a907d7b17e30ff1d","includes\/crm_hub.php":"7852648c59cb7ec5b31e07c15cdb3027","assets\/css\/wm-crm.css":"602c3c78f752a99c29563f97c0c807f2","includes\/feature_hub.php":"abb1cda0263197db971d94f471171402","includes\/hub_data\/marketing.php":"d8b475ede9714b738260d99e8c0ad609","includes\/hub_data\/commerce.php":"9d985ff9dd82fb69d3f07c1b95d0d8f0","includes\/hub_data\/billing.php":"df907db4ec1121e388236d690a050e58","includes\/hub_data\/reminders.php":"3361651f642c00dfdfada8a4ed42c10e","assets\/css\/wm-feat.css":"7c96b8cf701d9c5956e4b5b9ef917b93","includes\/hub_data\/d2c.php":"f2a67bf937e678bb49a241e9f860ce42","includes\/hub_data\/realestate.php":"7ac3d1205820026108847f6850e60e75","includes\/hub_data\/education.php":"9d06e262b43d7301c3e2889f0f19f560","includes\/support_art.php":"2e13141d447f93a63ff6dfa4fc29c58e","client\/guide_catalog.php":"42cf57ff738c85136c26d46a8c0cc2b3","includes\/hub_data\/healthcare.php":"a628fe050eb6e72fe2dd5f2439cbcc27","client\/guide_i18n_tl.php":"3243e07f1ed0b58eee4d46cd5c858893","client\/guide_i18n_hi.php":"5486a3515aada3513ee6292449893411","client\/guide_i18n_ta.php":"66ad93d1198164a586e6673157b96dea"}', true) ?: [];

function dg_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function dg_mask(string $s): string {
    $s = preg_replace('/(sk-|EAA|AIza|gsk_|xai-|wamid\.)[A-Za-z0-9_\-\.]{6,}/', '$1****', $s);
    $s = preg_replace('/\b[a-f0-9]{32,}\b/i', '****', $s);
    return str_replace((string)realpath(__DIR__ . '/..'), '', $s);
}

// 1. latest errors
$logFile = $root . '/storage/logs/error.log';
$errors = [];
if (is_file($logFile)) {
    $size = filesize($logFile);
    $fh = fopen($logFile, 'r');
    if ($size > 400000) fseek($fh, $size - 400000);
    $lines = explode("\n", (string)stream_get_contents($fh));
    fclose($fh);
    foreach (array_reverse($lines) as $l) {
        if (preg_match('/EXCEPTION|FATAL|PHP ERROR|WEBHOOK|SmartRouter|SMART ROUTER|FlowJob|flow engine|WAAMINI Bot|gmeet|Google Calendar|Meet|demo/i', $l)) {
            $errors[] = dg_mask(mb_substr($l, 0, 400));
            if (count($errors) >= 40) break;
        }
    }
}

// 2. file check against the package
$missing = []; $different = [];
foreach ($expected as $rel => $md5) {
    $p = $root . '/' . $rel;
    if (!is_file($p)) { $missing[] = $rel; continue; }
    if (md5_file($p) !== $md5) $different[] = $rel . '  (server copy ' . date('Y-m-d H:i', (int)filemtime($p)) . ')';
}

// 3. environment
$disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
$dbOk = $conn instanceof mysqli && @$conn->query('SELECT 1');
$tables = $dbOk ? (int)$conn->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetch_row()[0] : 0;
$sqlMode = $dbOk ? (string)$conn->query('SELECT @@SESSION.sql_mode')->fetch_row()[0] : '';
// 4. AI bot reply chain: message in -> routing -> queue -> AI run -> WhatsApp send
$ai = [];
$q = static function (string $sql) use ($conn): array {
    if (!($conn instanceof mysqli)) return [];
    try { $r = @$conn->query($sql); return $r ? $r->fetch_all(MYSQLI_ASSOC) : [['error' => $conn->error]]; } catch (Throwable $e) { return [['error' => $e->getMessage()]]; }
};
if ($dbOk ?? ($conn instanceof mysqli)) {
    $ai['Platform (Admin > WAAMINI Bot)'] = $q("SELECT is_enabled, IF(openai_key_encrypted IS NOT NULL AND openai_key_encrypted<>'','yes','no') AS openai_key_saved, key_ok AS test_passed, chat_provider, chat_model, LEFT(IFNULL(key_error,''),120) AS last_test_error FROM ai_platform_settings WHERE id=1");
    $ai['Other provider keys'] = $q("SELECT provider, CONCAT('...',key_last4) AS key_end FROM ai_provider_credentials");
    $ai['Client bots (all must be 1 / set for the bot to be live)'] = $q("SELECT b.company_id, c.name AS company, b.is_admin_enabled, b.is_live, IF(b.tested_at IS NULL,'NOT tested',b.tested_at) AS tested, IF(b.profile_confirmed_at IS NULL,'NOT confirmed',b.profile_confirmed_at) AS profile, b.reply_mode FROM ai_bots b LEFT JOIN companies c ON c.id=b.company_id");
    $ai['Last 5 incoming WhatsApp messages'] = $q("SELECT id, company_id, contact_id, created_at, LEFT(IFNULL(content,message),40) AS text FROM messages WHERE direction='incoming' ORDER BY id DESC LIMIT 5");
    $ai['Last 5 routing decisions (who answered)'] = $q("SELECT created_at, company_id, contact_id, routed_to, LEFT(IFNULL(incoming_message,''),30) AS text, LEFT(IFNULL(response_sent,''),60) AS response FROM message_routing_log ORDER BY id DESC LIMIT 5");
    $ai['Last 5 AI reply jobs (queue)'] = $q("SELECT id, company_id, contact_id, status, attempts, run_at, created_at, LEFT(IFNULL(last_error,''),150) AS last_error FROM ai_reply_jobs ORDER BY id DESC LIMIT 5");
    $ai['Last 5 AI runs'] = $q("SELECT created_at, company_id, contact_id, outcome, latency_ms, LEFT(IFNULL(error,''),150) AS error FROM ai_runs WHERE is_test=0 ORDER BY id DESC LIMIT 5");
    $ai['Conversation state (paused / human takeover)'] = $q("SELECT company_id, contact_id, state, paused_until FROM ai_conversation_state ORDER BY contact_id DESC LIMIT 5");
    $ai['Last 5 outgoing WhatsApp messages'] = $q("SELECT id, company_id, contact_id, created_at, status, IFNULL(error_code,'') AS error_code, LEFT(IFNULL(error_message,''),100) AS error, LEFT(IFNULL(content,message),40) AS text FROM messages WHERE direction='outgoing' ORDER BY id DESC LIMIT 5");
    $ai['WhatsApp sending setup'] = $q("SELECT company_id, IF(IFNULL(phone_number_id,'')<>'','set','MISSING') AS phone_number_id, IF(IFNULL(access_token,'')<>'','saved','MISSING') AS access_token, status, api_version FROM whatsapp_config");
}
// Ask the bot itself why it would or would not answer the latest incoming message (same code the router uses).
$why = [];
try {
    require_once $root . '/includes/waamini_bot/bootstrap.php';
    $last = $q("SELECT company_id, contact_id, created_at FROM messages WHERE direction='incoming' AND contact_id > 0 ORDER BY id DESC LIMIT 1");
    if ($last && empty($last[0]['error'])) {
        $co = (int)$last[0]['company_id']; $ct = (int)$last[0]['contact_id'];
        $s = WB_Store::settings($conn);
        $bot = WB_Store::bot($conn, $co);
        [$ok, $reason] = WB_Guard::eligible($conn, $co, $ct);
        $limits = WB_Store::limitsFor($conn, $co); $usage = WB_Store::usage($conn, $co);
        $explain = [
            'ok' => 'the bot WILL answer this chat', 'bot_not_live' => 'bot is not fully live (see the checks above)',
            'assigned_to_human' => 'this chat is assigned to a person, so the bot stays quiet', 'paused' => 'the bot is paused for this chat (a person replied recently)',
            'limit_reached' => 'monthly AI reply limit reached for this plan', 'business_hours' => 'reply mode is "only outside business hours" and it is business hours now',
            'contact_missing' => 'contact not found',
        ];
        $why = [[
            'latest message' => $last[0]['created_at'] . ' company ' . $co . ' contact ' . $ct,
            'platform enabled' => !empty($s['is_enabled']) ? 'yes' : 'NO', 'platform key saved' => !empty($s['has_key']) ? 'yes' : 'NO', 'platform test passed' => !empty($s['key_ok']) ? 'yes' : 'NO',
            'bot exists' => $bot ? 'yes' : 'NO', 'admin enabled' => !empty($bot['is_admin_enabled']) ? 'yes' : 'NO', 'client live' => !empty($bot['is_live']) ? 'yes' : 'NO',
            'tested' => !empty($bot['tested_at']) ? 'yes' : 'NO', 'profile confirmed' => !empty($bot['profile_confirmed_at']) ? 'yes' : 'NO',
            'reply mode' => (string)($bot['reply_mode'] ?? '-'), 'replies used / limit' => (int)($usage['replies'] ?? 0) . ' / ' . (int)($limits['replies'] ?? 0),
            'DECISION' => ($ok ? 'YES - ' : 'NO - ') . $reason . ': ' . ($explain[$reason] ?? $reason),
        ]];
    }
} catch (Throwable $e) {
    $why = [['error' => $e->getMessage()]];
}
if ($why) $ai = ['WHY NO REPLY? (the bot\'s own decision for the latest message)' => $why] + $ai;

// 5. Google Meet booking by the bot: needs Meet keys, a connected Google Calendar per client, Meet allowed, a clock time
$meet = [];
try {
    require_once $root . '/includes/google_meet_helper.php';
    $companiesWithBot = $q("SELECT DISTINCT b.company_id, c.name FROM ai_bots b LEFT JOIN companies c ON c.id = b.company_id");
    $rowsMeet = [];
    foreach ($companiesWithBot as $cb) {
        if (!isset($cb['company_id'])) continue;
        $co = (int)$cb['company_id'];
        $cfg = gmeet_oauth_config($conn, $co);
        $conn_email = gmeet_connected($conn, $co);
        $rowsMeet[] = [
            'company' => $co . ' ' . (string)($cb['name'] ?? ''),
            'Meet keys (Google app)' => $cfg['ready'] ? 'yes (' . $cfg['source'] . ')' : 'NO - add keys in .env or Admin > Google Login (Meet)',
            'Google Calendar connected' => $conn_email !== null ? 'yes: ' . $conn_email : 'NO - client: Google Calendar > Connect',
            'Meet allowed for client' => gmeet_meet_enabled($conn, $co) ? 'yes' : 'NO - switched off in Admin > Google Login',
        ];
    }
    $meet['Can the bot create Google Meets?'] = $rowsMeet ?: [['info' => 'no client has a WAAMINI Bot yet']];
    $meet['Last 5 demo bookings'] = $q("SELECT id, company_id, created_at, customer_name, preferred_time, demo_datetime, status, IF(IFNULL(meet_link,'')<>'','yes','NO LINK') AS meet_link FROM demo_bookings ORDER BY id DESC LIMIT 5");
} catch (Throwable $e) {
    $meet['Google Meet check'] = [['error' => $e->getMessage()]];
}

$botLock = $root . '/storage/waamini_bot.lock';
$cronHint = is_file($botLock) ? 'worker lock last touched ' . date('Y-m-d H:i:s', (int)filemtime($botLock)) : 'worker has never run (no storage/waamini_bot.lock)';

$writable = [];
foreach (['storage', 'storage/logs', 'storage/sessions', 'storage/cache', 'uploads'] as $d) $writable[$d] = is_writable($root . '/' . $d);
?><!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Waamini diagnostics</title>
<style>body{font:14px/1.5 system-ui,Segoe UI,sans-serif;margin:20px;color:#0f172a;background:#f8fafc}h1{font-size:20px}h2{font-size:16px;margin-top:26px}
.box{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px;overflow:auto}pre{margin:0;white-space:pre-wrap;word-break:break-all;font-size:12px}
.ok{color:#047857;font-weight:600}.bad{color:#b91c1c;font-weight:600}table{border-collapse:collapse}td{padding:3px 12px 3px 0;vertical-align:top}</style></head><body>
<h1>Waamini diagnostics</h1>
<p>Send a screenshot of this whole page (scroll down). Delete <code>admin/system_report.php</code> when the problem is fixed.</p>

<h2>1. Latest errors (newest first)</h2>
<div class="box"><pre><?php echo $errors ? dg_h(implode("\n", $errors)) : 'No error lines found in storage/logs/error.log'; ?></pre></div>

<h2>2. Files compared with the deployed package (<?php echo count($expected); ?> files)</h2>
<div class="box">
<p class="<?php echo $missing ? 'bad' : 'ok'; ?>">Missing: <?php echo count($missing); ?></p>
<?php if ($missing): ?><pre><?php echo dg_h(implode("\n", $missing)); ?></pre><?php endif; ?>
<p class="<?php echo $different ? 'bad' : 'ok'; ?>">Different from the package (old or edited copy): <?php echo count($different); ?></p>
<?php if ($different): ?><pre><?php echo dg_h(implode("\n", $different)); ?></pre><?php endif; ?>
</div>

<h2>3. Server</h2>
<div class="box"><table>
<tr><td>PHP version</td><td><?php echo dg_h(PHP_VERSION . ' (' . PHP_SAPI . ')'); ?></td></tr>
<tr><td>Database</td><td class="<?php echo $dbOk ? 'ok' : 'bad'; ?>"><?php echo $dbOk ? "connected, $tables tables" : 'NOT connected'; ?></td></tr>
<tr><td>SQL mode (session)</td><td><?php echo dg_h($sqlMode); ?></td></tr>
<tr><td>Finish-request support</td><td><?php echo function_exists('litespeed_finish_request') ? 'litespeed_finish_request' : (function_exists('fastcgi_finish_request') ? 'fastcgi_finish_request' : '<span class="bad">none</span>'); ?></td></tr>
<tr><td>Disabled functions</td><td><?php echo dg_h($disabled ? implode(', ', $disabled) : 'none'); ?></td></tr>
<?php foreach ($writable as $d => $w): ?><tr><td>Writable: <?php echo dg_h($d); ?></td><td class="<?php echo $w ? 'ok' : 'bad'; ?>"><?php echo $w ? 'yes' : 'NO'; ?></td></tr><?php endforeach; ?>
<tr><td>APP_ENV / APP_URL</td><td><?php echo dg_h((string)(getenv('APP_ENV') ?: '-') . ' / ' . (string)(getenv('APP_URL') ?: '-')); ?></td></tr>
<tr><td>META_APP_SECRET set</td><td><?php echo (getenv('META_APP_SECRET') ?: getenv('FB_APP_SECRET')) ? '<span class="ok">yes</span>' : '<span class="bad">NO</span>'; ?></td></tr>
<tr><td>Time (server / PHP)</td><td><?php echo dg_h(date('Y-m-d H:i:s') . ' ' . date_default_timezone_get()); ?></td></tr>
<tr><td>AI worker</td><td><?php echo dg_h($cronHint); ?></td></tr>
<tr><td>Google login keys in .env</td><td><?php
    $gId = (string)(getenv('GOOGLE_CLIENT_ID') ?: ''); $gSec = (string)(getenv('GOOGLE_CLIENT_SECRET') ?: ''); $gRed = (string)(getenv('GOOGLE_REDIRECT_URI') ?: '');
    echo ($gId !== '' && $gSec !== '' && $gRed !== '') ? '<span class="ok">set</span> (redirect ' . dg_h($gRed) . ')'
        : '<span class="bad">NOT SET</span> - missing: ' . dg_h(implode(', ', array_keys(array_filter(['GOOGLE_CLIENT_ID' => $gId === '', 'GOOGLE_CLIENT_SECRET' => $gSec === '', 'GOOGLE_REDIRECT_URI' => $gRed === '']))));
?></td></tr>
</table></div>

<h2>4. AI bot reply chain</h2>
<?php foreach ($ai as $title => $rows): ?>
<div class="box" style="margin-bottom:10px"><b><?php echo dg_h($title); ?></b>
<?php if (!$rows): ?><p class="bad">no rows</p><?php else: ?>
<table style="margin-top:6px;font-size:12px"><tr><?php foreach (array_keys($rows[0]) as $k): ?><td><b><?php echo dg_h((string)$k); ?></b></td><?php endforeach; ?></tr>
<?php foreach ($rows as $r): ?><tr><?php foreach ($r as $v): ?><td><?php echo dg_h(dg_mask((string)$v)); ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</table><?php endif; ?></div>
<?php endforeach; ?>

<h2>5. Google Meet (bot books demos)</h2>
<p style="font-size:13px;color:#475569">The bot books a Meet only when the customer asks for a demo/meeting AND writes a clock time, e.g. "demo tomorrow at 4 pm".</p>
<?php foreach ($meet as $title => $rows): ?>
<div class="box" style="margin-bottom:10px"><b><?php echo dg_h($title); ?></b>
<?php if (!$rows): ?><p class="bad">no rows</p><?php else: ?>
<table style="margin-top:6px;font-size:12px"><tr><?php foreach (array_keys($rows[0]) as $k): ?><td><b><?php echo dg_h((string)$k); ?></b></td><?php endforeach; ?></tr>
<?php foreach ($rows as $r): ?><tr><?php foreach ($r as $v): ?><td><?php echo dg_h(dg_mask((string)$v)); ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</table><?php endif; ?></div>
<?php endforeach; ?>

<?php
// 6. Cron jobs: last run of every job in dist/HOSTINGER_CRON_JOBS.txt (recorded by includes/cron_runlog.php)
$cronJobs = [   // script => [what it does, schedule text, allowed seconds since last start before it counts as late]
    'process_ai_queue.php'           => ['Main worker (flows, drips, demos)', 'every minute', 600],
    'process_waamini_bot.php'        => ['WAAMINI AI bot worker', 'every minute', 600],
    'cron_campaign_sender.php'       => ['Broadcast campaigns', 'every minute', 600],
    'cron_automation_sender.php'     => ['Automation / follow-up queue', 'every minute', 600],
    'cron_cart_recovery.php'         => ['Abandoned cart recovery', 'every 30 min', 3600],
    'cron_medicine_reminder.php'     => ['Medicine reminders', 'every 30 min', 3600],
    'cron_indiamart_sync.php'        => ['IndiaMART lead sync', 'hourly', 7200],
    'cron_pulse_analyzer.php'        => ['Pulse analyzer', 'hourly', 7200],
    'cron_festival.php'              => ['Festival calendar', 'daily 8:00', 93600],
    'cron_birthday_sender.php'       => ['Birthdays / occasions', 'daily 9:00', 93600],
    'cron_festival_sender.php'       => ['Festival greetings', 'daily 9:02', 93600],
    'cron_fee_reminder.php'          => ['Fee reminders', 'daily 9:04', 93600],
    'cron_school_fee_reminder.php'   => ['School fee reminders', 'daily 9:06', 93600],
    'cron_subscription_reminder.php' => ['Subscription renewal reminders', 'daily 9:08', 93600],
    'cron_trial_nudge.php'           => ['Trial nudges', 'daily 10:00', 93600],
    'cron_vehicle_reminder.php'      => ['Vehicle service reminders', 'daily 10:02', 93600],
    'cron_subscription_alerts.php'   => ['Subscription expiry alerts', 'daily 10:04', 93600],
    'cron_review_request.php'        => ['Review requests', 'daily 11:00', 93600],
    'cron_winback.php'               => ['Win-back messages', 'Mondays 11:00', 8 * 86400],
    'cron_eod_sheets.php'            => ['Google Sheets export', 'daily 23:59', 93600],
];
$cronRuns = json_decode((string)@file_get_contents($root . '/storage/cron_runs.json'), true);
if (!is_array($cronRuns)) $cronRuns = [];
$ago = static function (int $t): string {
    $s = time() - $t;
    if ($s < 90) return $s . ' s ago';
    if ($s < 5400) return round($s / 60) . ' min ago';
    if ($s < 172800) return round($s / 3600, 1) . ' h ago';
    return round($s / 86400, 1) . ' days ago';
};
$cronOk = 0;
?>
<?php
// 7. Shopify: why a client may not see it (files, switch, keys, connected stores)
$shp = [];
foreach (['includes/shopify.php', 'client/shopify_connect.php', 'client/shopify_callback.php', 'api/shopify_webhook.php', 'cron_shopify.php'] as $sf) {
    $shp['File ' . $sf] = is_file($root . '/' . $sf) ? ['OK: uploaded', 'ok'] : ['MISSING: upload this file', 'bad'];
}
$fc = (string)@file_get_contents($root . '/includes/feature_check.php');
$shp['includes/feature_check.php knows "shopify"'] = strpos($fc, "'shopify' => 'feat_shopify'") !== false ? ['OK', 'ok'] : ['OLD FILE: upload the new includes/feature_check.php', 'bad'];
$ch = (string)@file_get_contents($root . '/includes/client_header.php');
$shp['Menu unlocks Integrations for Shopify'] = strpos($ch, "has_feature('shopify')") !== false ? ['OK', 'ok'] : ['OLD FILE: upload the new includes/client_header.php', 'bad'];
$ig = (string)@file_get_contents($root . '/client/integrations.php');
$shp['Integrations page has the Shopify card'] = strpos($ig, 'shopify_connect.php') !== false ? ['OK', 'ok'] : ['OLD FILE: upload the new client/integrations.php', 'bad'];
$shp['SHOPIFY_CLIENT_ID / SECRET in .env'] = (class_exists('\Waamini\Config\Env') && \Waamini\Config\Env::get('SHOPIFY_CLIENT_ID', '') !== '' && \Waamini\Config\Env::get('SHOPIFY_CLIENT_SECRET', '') !== '')
    ? ['OK: both set', 'ok'] : ['MISSING: add both lines to public_html/.env (needed to connect, not to see the card)', 'bad'];
$shpPlans = []; $shpCos = []; $colOk = false;
try {
    $c = $conn->query("SHOW COLUMNS FROM subscription_plans LIKE 'feat_shopify'");
    $colOk = $c && $c->num_rows > 0;
    if ($colOk) { $r = $conn->query("SELECT name FROM subscription_plans WHERE feat_shopify = 1"); while ($r && ($x = $r->fetch_row())) $shpPlans[] = $x[0]; }
    $r = $conn->query("SELECT id, name, features_override FROM companies WHERE features_override LIKE '%feat_shopify%'");
    while ($r && ($x = $r->fetch_assoc())) { $o = json_decode((string)$x['features_override'], true); $shpCos[] = $x['name'] . ' (#' . $x['id'] . '): ' . (!empty($o['feat_shopify']) ? 'ON' : 'OFF'); }
} catch (\Throwable $e) {}
$shp['Shopify switch in the database'] = $colOk ? ['OK', 'ok'] : ['NOT CREATED: open Admin > Plans > Edit any plan once (needs the new admin/plan_edit.php)', 'bad'];
$shp['Plans with Shopify ticked'] = $shpPlans ? [implode(', ', $shpPlans), 'ok'] : ['NONE: tick Shopify under "Shopify add-on" in Admin > Plans > Edit', 'bad'];
$shp['Companies with a Shopify override'] = $shpCos ? [implode(' | ', $shpCos), 'ok'] : ['none (they follow their plan)', ''];
try {
    $r = $conn->query("SELECT shop_domain, status, webhooks_ok, last_event_at FROM shopify_stores");
    $rows = [];
    while ($r && ($x = $r->fetch_assoc())) $rows[] = $x['shop_domain'] . ' = ' . $x['status'] . ($x['webhooks_ok'] ? ', events OK' : ', events NOT set') . ($x['last_event_at'] ? ', last event ' . $x['last_event_at'] : '');
    $shp['Connected stores'] = $rows ? [implode(' | ', $rows), 'ok'] : ['none yet', ''];
} catch (\Throwable $e) { $shp['Connected stores'] = ['none yet (table is created on first connect)', '']; }
// every company: plan + whether Shopify is really on (what the client sees)
$shpEach = [];
try {
    $r = $conn->query("SELECT c.id, c.name, c.features_override,
            (SELECT u.email FROM users u WHERE u.company_id = c.id AND u.role <> 'super_admin' ORDER BY u.id LIMIT 1) AS login,
            (SELECT sp.name FROM subscriptions s JOIN subscription_plans sp ON sp.id = s.plan_id WHERE s.company_id = c.id ORDER BY (s.status IN ('active','trial')) DESC, s.id DESC LIMIT 1) AS plan,
            (SELECT " . ($colOk ? 'sp.feat_shopify' : '0') . " FROM subscriptions s JOIN subscription_plans sp ON sp.id = s.plan_id WHERE s.company_id = c.id ORDER BY (s.status IN ('active','trial')) DESC, s.id DESC LIMIT 1) AS plan_on
          FROM companies c ORDER BY c.id");
    while ($r && ($x = $r->fetch_assoc())) {
        $o = json_decode((string)$x['features_override'], true);
        $hasOv = is_array($o) && array_key_exists('feat_shopify', $o);
        $on = $hasOv ? !empty($o['feat_shopify']) : !empty($x['plan_on']);
        $why = $hasOv ? 'company override ' . ($on ? 'ON' : 'OFF') : ('plan ' . ($x['plan'] ?: 'none') . ($on ? ' has Shopify' : ' does not have Shopify'));
        $shpEach[] = ['#' . $x['id'] . ' ' . $x['name'], (string)($x['login'] ?? '-'), (string)($x['plan'] ?? '-'), $on, $why];
    }
} catch (\Throwable $e) {}
?>
<h2>7. Shopify</h2>
<p style="font-size:13px;color:#475569">A client sees Integrations &gt; Shopify only when all file rows are OK and Shopify is on for their plan (or their company override is ON). Clients pick up a change within about 2 minutes or after logging in again.</p>
<div class="box"><table style="font-size:12.5px">
<?php foreach ($shp as $k => [$v, $cls]): ?><tr><td><b><?php echo dg_h($k); ?></b></td><td class="<?php echo $cls; ?>"><?php echo dg_h($v); ?></td></tr><?php endforeach; ?>
</table>
<p style="margin:14px 0 6px;font-size:13px"><b>Who sees Shopify</b> (log in as that client; they must log out and in after a change):</p>
<table style="font-size:12.5px">
<tr><td><b>Company</b></td><td><b>Client login</b></td><td><b>Plan</b></td><td><b>Shopify</b></td><td><b>Why</b></td></tr>
<?php foreach ($shpEach as [$co, $login, $plan, $on, $why]): ?>
<tr><td><?php echo dg_h($co); ?></td><td><?php echo dg_h($login); ?></td><td><?php echo dg_h($plan); ?></td><td class="<?php echo $on ? 'ok' : 'bad'; ?>"><b><?php echo $on ? 'ON' : 'OFF'; ?></b></td><td><?php echo dg_h($why); ?></td></tr>
<?php endforeach; ?>
</table></div>

<h2>6. Cron jobs</h2>
<p style="font-size:13px;color:#475569">Each job is recorded when Hostinger runs it (times are server time, <?php echo dg_h(date('T P')); ?>). A job added today shows <b>NEVER RAN</b> until its first scheduled time.</p>
<div class="box"><table style="font-size:12.5px">
<tr><td><b>Status</b></td><td><b>Job</b></td><td><b>Schedule (India)</b></td><td><b>Last start</b></td><td><b>Last finish</b></td><td><b>Took</b></td><td><b>Runs</b></td><td><b>Recent starts</b></td></tr>
<?php foreach ($cronJobs as $script => [$what, $when, $maxAge]):
    $r = $cronRuns[$script] ?? null;
    if (!$r) { $st = ['NEVER RAN', 'bad']; }
    elseif (!empty($r['running']) && time() - (int)$r['started'] < 900) { $st = ['RUNNING', 'ok']; }
    elseif (!empty($r['error'])) { $st = ['ERROR', 'bad']; }
    elseif (time() - (int)$r['started'] > $maxAge) { $st = ['LATE', 'bad']; }
    else { $st = ['OK', 'ok']; $cronOk++; }
?>
<tr>
  <td class="<?php echo $st[1]; ?>"><b><?php echo $st[0]; ?></b></td>
  <td><?php echo dg_h($what); ?><br><span style="color:#64748b"><?php echo dg_h($script); ?></span><?php if (!empty($r['error'])): ?><br><span class="bad"><?php echo dg_h((string)$r['error']); ?></span><?php endif; ?></td>
  <td><?php echo dg_h($when); ?></td>
  <td><?php echo $r ? dg_h(date('d M H:i:s', (int)$r['started'])) . '<br><span style="color:#64748b">' . dg_h($ago((int)$r['started'])) . '</span>' : '—'; ?></td>
  <td><?php echo !empty($r['finished']) ? dg_h(date('d M H:i:s', (int)$r['finished'])) : '—'; ?></td>
  <td><?php echo isset($r['seconds']) ? dg_h((string)$r['seconds']) . ' s' : '—'; ?></td>
  <td><?php echo (int)($r['runs'] ?? 0); ?></td>
  <td style="color:#475569;white-space:nowrap"><?php
    $rec = array_map('intval', (array)($r['recent'] ?? []));
    // a job that is not every-minute / 30-min / hourly but ran twice within 6 hours has a wrong schedule
    $twice = $maxAge >= 93600 && count($rec) >= 2 && ($rec[0] - $rec[1]) < 6 * 3600;
    echo $rec ? dg_h(implode(', ', array_map(static fn($t) => date('d M H:i', $t), array_slice($rec, 0, 3)))) : '—';
    if ($twice) echo '<br><span class="bad">Ran twice close together: check this job\'s schedule in hPanel (Minute box must be one number, not "0,30").</span>';
  ?></td>
</tr>
<?php endforeach; ?>
</table>
<p style="margin:10px 0 0;font-size:13px"><b><?php echo $cronOk; ?> / <?php echo count($cronJobs); ?></b> jobs ran on schedule. LATE = it has not run for longer than its schedule allows (check the job in hPanel &gt; Cron Jobs &gt; View output). ERROR = PHP stopped with the message shown.</p>
</div>
</body></html>
