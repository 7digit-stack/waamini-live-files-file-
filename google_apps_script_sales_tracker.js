/**
 * WAAMINI Sales Tracker — Google Apps Script
 * Deploy this as a Web App (Execute as: Me, Access: Anyone)
 *
 * Paste this into Google Apps Script (Extensions > Apps Script in your Google Sheet)
 * Then: Deploy > New Deployment > Web App > Copy URL > Paste in Waamini Sales Tracker settings
 */

function doPost(e) {
  try {
    var data = JSON.parse(e.postData.contents);
    var ss = SpreadsheetApp.getActiveSpreadsheet();

    // Get or create "Sales Log" sheet
    var sheet = ss.getSheetByName("Sales Log");
    if (!sheet) {
      sheet = ss.insertSheet("Sales Log");
      sheet.appendRow(["Timestamp", "Item Description", "Quantity", "Price", "Phone"]);

      // Format header row
      var headerRange = sheet.getRange(1, 1, 1, 5);
      headerRange.setFontWeight("bold");
      headerRange.setBackground("#4CAF50");
      headerRange.setFontColor("#FFFFFF");

      // Set column widths
      sheet.setColumnWidth(1, 180); // Timestamp
      sheet.setColumnWidth(2, 200); // Item
      sheet.setColumnWidth(3, 100); // Quantity
      sheet.setColumnWidth(4, 120); // Price
      sheet.setColumnWidth(5, 150); // Phone
    }

    // Data comes as an array of sale objects
    if (Array.isArray(data)) {
      for (var i = 0; i < data.length; i++) {
        var sale = data[i];
        sheet.appendRow([
          sale.timestamp || new Date().toISOString(),
          sale.item || "",
          sale.quantity || 0,
          sale.price || 0,       // <-- THIS was likely missing in your old script
          sale.phone || ""
        ]);
      }
    }

    // Format price column as currency (Indian Rupees)
    var lastRow = sheet.getLastRow();
    if (lastRow > 1) {
      sheet.getRange(2, 4, lastRow - 1, 1).setNumberFormat("₹#,##0");
    }

    return ContentService
      .createTextOutput(JSON.stringify({ status: "success", rows: data.length }))
      .setMimeType(ContentService.MimeType.JSON);

  } catch (err) {
    return ContentService
      .createTextOutput(JSON.stringify({ status: "error", message: err.toString() }))
      .setMimeType(ContentService.MimeType.JSON);
  }
}

function doGet(e) {
  return ContentService
    .createTextOutput(JSON.stringify({ status: "ok", service: "Waamini Sales Tracker" }))
    .setMimeType(ContentService.MimeType.JSON);
}
