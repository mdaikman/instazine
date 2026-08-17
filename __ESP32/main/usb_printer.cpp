#include "usb_printer.h"

#include <algorithm>
#include <atomic>
#include <cstdint>
#include <cstdio>
#include <cstring>
#include <string>

#include "esp_err.h"
#include "esp_intr_alloc.h"
#include "esp_log.h"
#include "freertos/FreeRTOS.h"
#include "freertos/queue.h"
#include "freertos/semphr.h"
#include "freertos/task.h"
#include "printer_encoding.h"
#include "settings.h"
#include "usb/usb_host.h"

namespace
{
constexpr std::size_t MAX_TRANSFER_BYTES = 4096;
constexpr int USB_EVENT_QUEUE_DEPTH = 8;

const char *TAG = "usb_printer";

struct print_job_t
{
    const uint8_t *data;
    std::size_t length;
    SemaphoreHandle_t completion;
    esp_err_t result;
};

struct transfer_result_t
{
    bool completed;
    usb_transfer_status_t status;
    int actual_bytes;
};

struct printer_state_t
{
    usb_host_client_handle_t client = nullptr;
    usb_device_handle_t device = nullptr;
    uint8_t pending_address = 0;
    uint8_t interface_number = 0;
    uint8_t alternate_setting = 0;
    uint8_t bulk_out_endpoint = 0;
    uint8_t bulk_in_endpoint = 0;
    bool open_pending = false;
    bool close_pending = false;
};

QueueHandle_t print_queue;
SemaphoreHandle_t write_mutex;
std::atomic<bool> printer_ready{false};

esp_err_t send_chunk(printer_state_t &state, const uint8_t *data,
                     std::size_t length);

void transfer_complete(usb_transfer_t *transfer)
{
    auto *result = static_cast<transfer_result_t *>(transfer->context);
    result->status = transfer->status;
    result->actual_bytes = transfer->actual_num_bytes;
    result->completed = true;
}

void client_event_callback(const usb_host_client_event_msg_t *event,
                           void *argument)
{
    auto *state = static_cast<printer_state_t *>(argument);

    if (event->event == USB_HOST_CLIENT_EVENT_NEW_DEV)
    {
        if (state->device == nullptr && !state->open_pending)
        {
            state->pending_address = event->new_dev.address;
            state->open_pending = true;
        }
    }
    else if (event->event == USB_HOST_CLIENT_EVENT_DEV_GONE &&
             event->dev_gone.dev_hdl == state->device)
    {
        printer_ready.store(false);
        state->close_pending = true;
    }
}

bool inspect_and_claim_printer(printer_state_t &state)
{
    const usb_device_desc_t *device_descriptor = nullptr;
    esp_err_t result =
        usb_host_get_device_descriptor(state.device, &device_descriptor);
    if (result != ESP_OK || device_descriptor == nullptr)
    {
        ESP_LOGE(TAG, "Unable to read device descriptor: %s",
                 esp_err_to_name(result));
        return false;
    }

    ESP_LOGI(TAG, "USB device %04X:%04X",
             device_descriptor->idVendor, device_descriptor->idProduct);

    if (device_descriptor->idVendor != PRINTER_USB_VENDOR_ID ||
        device_descriptor->idProduct != PRINTER_USB_PRODUCT_ID)
    {
        ESP_LOGI(TAG, "Ignoring USB device; waiting for Bttl %04X:%04X",
                 PRINTER_USB_VENDOR_ID, PRINTER_USB_PRODUCT_ID);
        return false;
    }

    const usb_config_desc_t *configuration = nullptr;
    result = usb_host_get_active_config_descriptor(state.device,
                                                    &configuration);
    if (result != ESP_OK || configuration == nullptr)
    {
        ESP_LOGE(TAG, "Unable to read configuration descriptor: %s",
                 esp_err_to_name(result));
        return false;
    }

    usb_print_config_descriptor(configuration, nullptr);

    const uint16_t total_length = configuration->wTotalLength;
    const auto *descriptor =
        reinterpret_cast<const usb_standard_desc_t *>(configuration);
    int offset = 0;
    bool current_is_printer = false;
    uint8_t current_interface = 0;
    uint8_t current_alternate = 0;
    bool found_interface = false;

    while (descriptor != nullptr && offset < total_length)
    {
        if (descriptor->bDescriptorType == USB_B_DESCRIPTOR_TYPE_INTERFACE)
        {
            const auto *interface_descriptor =
                reinterpret_cast<const usb_intf_desc_t *>(descriptor);
            current_interface = interface_descriptor->bInterfaceNumber;
            current_alternate = interface_descriptor->bAlternateSetting;
            current_is_printer =
                interface_descriptor->bInterfaceClass == USB_CLASS_PRINTER;

            ESP_LOGI(TAG,
                     "interface %u alt %u: class 0x%02X subclass 0x%02X protocol 0x%02X",
                     current_interface, current_alternate,
                     interface_descriptor->bInterfaceClass,
                     interface_descriptor->bInterfaceSubClass,
                     interface_descriptor->bInterfaceProtocol);
        }
        else if (descriptor->bDescriptorType ==
                     USB_B_DESCRIPTOR_TYPE_ENDPOINT)
        {
            const auto *endpoint =
                reinterpret_cast<const usb_ep_desc_t *>(descriptor);
            const bool is_bulk =
                USB_EP_DESC_GET_XFERTYPE(endpoint) == USB_TRANSFER_TYPE_BULK;
            const bool is_in = USB_EP_DESC_GET_EP_DIR(endpoint) != 0;

            ESP_LOGI(TAG, "endpoint 0x%02X: type %u, max packet %u",
                     endpoint->bEndpointAddress,
                     static_cast<unsigned>(USB_EP_DESC_GET_XFERTYPE(endpoint)),
                     static_cast<unsigned>(USB_EP_DESC_GET_MPS(endpoint)));

            if (current_is_printer && is_bulk)
            {
                if (!found_interface)
                {
                    state.interface_number = current_interface;
                    state.alternate_setting = current_alternate;
                    found_interface = true;
                }

                if (state.interface_number == current_interface &&
                    state.alternate_setting == current_alternate)
                {
                    if (is_in)
                    {
                        state.bulk_in_endpoint = endpoint->bEndpointAddress;
                    }
                    else
                    {
                        state.bulk_out_endpoint = endpoint->bEndpointAddress;
                    }
                }
            }
        }

        descriptor = usb_parse_next_descriptor(descriptor, total_length,
                                               &offset);
    }

    if (!found_interface || state.bulk_out_endpoint == 0)
    {
        ESP_LOGE(TAG, "Bttl has no USB Printer Class bulk OUT endpoint");
        return false;
    }

    result = usb_host_interface_claim(state.client, state.device,
                                      state.interface_number,
                                      state.alternate_setting);
    if (result != ESP_OK)
    {
        ESP_LOGE(TAG, "Unable to claim printer interface: %s",
                 esp_err_to_name(result));
        return false;
    }

    ESP_LOGI(TAG,
             "Bttl ready: interface %u, bulk OUT 0x%02X, bulk IN 0x%02X",
             state.interface_number, state.bulk_out_endpoint,
             state.bulk_in_endpoint);
    return true;
}

void open_device(printer_state_t &state)
{
    state.open_pending = false;
    const uint8_t address = state.pending_address;
    state.pending_address = 0;

    esp_err_t result =
        usb_host_device_open(state.client, address, &state.device);
    if (result != ESP_OK)
    {
        ESP_LOGE(TAG, "Unable to open USB device %u: %s", address,
                 esp_err_to_name(result));
        state.device = nullptr;
        return;
    }

    if (!inspect_and_claim_printer(state))
    {
        result = usb_host_device_close(state.client, state.device);
        if (result != ESP_OK)
        {
            ESP_LOGW(TAG, "Unable to close ignored USB device: %s",
                     esp_err_to_name(result));
        }
        state.device = nullptr;
        return;
    }

    printer_ready.store(true);

    std::string connection_receipt("\x1b\x40", 2);
    connection_receipt.append("\x1b\x74", 2); // ESC t: character table
    connection_receipt.push_back(static_cast<char>(PRINTER_CODE_PAGE));
    connection_receipt += printer_encode_windows_1252(OK_PHRASE);
    connection_receipt += "\r\n\n\n\n";
    connection_receipt.append("\x1d\x56\x00", 3); // GS V 0: full cut

    result = send_chunk(
        state,
        reinterpret_cast<const uint8_t *>(connection_receipt.data()),
        connection_receipt.size());
    if (result != ESP_OK)
    {
        ESP_LOGE(TAG, "Unable to print Bttl connection receipt: %s",
                 esp_err_to_name(result));
    }
}

void close_device(printer_state_t &state)
{
    state.close_pending = false;
    if (state.device == nullptr)
    {
        return;
    }

    esp_err_t result = usb_host_interface_release(
        state.client, state.device, state.interface_number);
    if (result != ESP_OK)
    {
        ESP_LOGW(TAG, "Unable to release disconnected interface: %s",
                 esp_err_to_name(result));
    }

    result = usb_host_device_close(state.client, state.device);
    if (result != ESP_OK)
    {
        ESP_LOGW(TAG, "Unable to close disconnected Bttl: %s",
                 esp_err_to_name(result));
    }

    state.device = nullptr;
    state.interface_number = 0;
    state.alternate_setting = 0;
    state.bulk_out_endpoint = 0;
    state.bulk_in_endpoint = 0;
    ESP_LOGI(TAG, "Bttl disconnected");
}

esp_err_t send_chunk(printer_state_t &state, const uint8_t *data,
                     std::size_t length)
{
    usb_transfer_t *transfer = nullptr;
    esp_err_t result = usb_host_transfer_alloc(length, 0, &transfer);
    if (result != ESP_OK)
    {
        return result;
    }

    std::memcpy(transfer->data_buffer, data, length);
    transfer_result_t transfer_result = {};
    transfer->num_bytes = static_cast<int>(length);
    transfer->device_handle = state.device;
    transfer->bEndpointAddress = state.bulk_out_endpoint;
    transfer->callback = transfer_complete;
    transfer->context = &transfer_result;

    result = usb_host_transfer_submit(transfer);
    while (result == ESP_OK && !transfer_result.completed)
    {
        result = usb_host_client_handle_events(state.client, portMAX_DELAY);
    }

    if (result == ESP_OK &&
        transfer_result.status != USB_TRANSFER_STATUS_COMPLETED)
    {
        ESP_LOGE(TAG, "USB transfer failed with status %d",
                 static_cast<int>(transfer_result.status));
        result = ESP_FAIL;
    }
    else if (result == ESP_OK &&
             transfer_result.actual_bytes != static_cast<int>(length))
    {
        ESP_LOGE(TAG, "Short USB write: %d of %u bytes",
                 transfer_result.actual_bytes,
                 static_cast<unsigned>(length));
        result = ESP_FAIL;
    }

    usb_host_transfer_free(transfer);
    return result;
}

void process_print_job(printer_state_t &state, print_job_t &job)
{
    if (!printer_ready.load() || state.device == nullptr)
    {
        job.result = ESP_ERR_INVALID_STATE;
        xSemaphoreGive(job.completion);
        return;
    }

    job.result = ESP_OK;
    std::size_t offset = 0;
    while (offset < job.length)
    {
        const std::size_t chunk_length =
            std::min(MAX_TRANSFER_BYTES, job.length - offset);
        job.result = send_chunk(state, job.data + offset, chunk_length);
        if (job.result != ESP_OK)
        {
            break;
        }
        offset += chunk_length;
    }

    xSemaphoreGive(job.completion);
}

void usb_host_daemon_task(void *argument)
{
    while (true)
    {
        uint32_t event_flags = 0;
        const esp_err_t result =
            usb_host_lib_handle_events(portMAX_DELAY, &event_flags);
        if (result != ESP_OK)
        {
            ESP_LOGE(TAG, "USB host event error: %s", esp_err_to_name(result));
        }
    }
}

void usb_printer_task(void *argument)
{
    printer_state_t state;
    usb_host_client_config_t client_config = {};
    client_config.is_synchronous = false;
    client_config.max_num_event_msg = USB_EVENT_QUEUE_DEPTH;
    client_config.async.client_event_callback = client_event_callback;
    client_config.async.callback_arg = &state;

    ESP_ERROR_CHECK(usb_host_client_register(&client_config, &state.client));
    ESP_LOGI(TAG, "Waiting for Bttl %04X:%04X",
             PRINTER_USB_VENDOR_ID, PRINTER_USB_PRODUCT_ID);

    while (true)
    {
        const esp_err_t event_result =
            usb_host_client_handle_events(state.client, pdMS_TO_TICKS(20));
        if (event_result != ESP_OK && event_result != ESP_ERR_TIMEOUT)
        {
            ESP_LOGE(TAG, "USB client event error: %s",
                     esp_err_to_name(event_result));
        }

        if (state.close_pending)
        {
            close_device(state);
        }
        if (state.open_pending)
        {
            open_device(state);
        }

        print_job_t *job = nullptr;
        if (xQueueReceive(print_queue, &job, 0) == pdTRUE && job != nullptr)
        {
            process_print_job(state, *job);
        }
    }
}
} // namespace

bool usb_printer_init()
{
    print_queue = xQueueCreate(4, sizeof(print_job_t *));
    write_mutex = xSemaphoreCreateMutex();
    if (print_queue == nullptr || write_mutex == nullptr)
    {
        ESP_LOGE(TAG, "Unable to allocate USB printer synchronization");
        return false;
    }

    usb_host_config_t host_config = {};
    host_config.skip_phy_setup = false;
    host_config.intr_flags = ESP_INTR_FLAG_LOWMED;
    host_config.peripheral_map = BIT0;

    esp_err_t result = usb_host_install(&host_config);
    if (result != ESP_OK)
    {
        ESP_LOGE(TAG, "Unable to install USB host: %s",
                 esp_err_to_name(result));
        return false;
    }

    if (xTaskCreate(usb_host_daemon_task, "usb_host", 4096, nullptr, 5,
                    nullptr) != pdPASS ||
        xTaskCreate(usb_printer_task, "usb_printer", 6144, nullptr, 5,
                    nullptr) != pdPASS)
    {
        ESP_LOGE(TAG, "Unable to create USB printer tasks");
        return false;
    }

    return true;
}

bool usb_printer_is_ready()
{
    return printer_ready.load();
}

bool usb_printer_write(const char *data, std::size_t length)
{
    if (data == nullptr || length == 0)
    {
        return length == 0;
    }
    if (!printer_ready.load() || print_queue == nullptr ||
        write_mutex == nullptr)
    {
        ESP_LOGE(TAG, "Bttl is not connected over USB");
        return false;
    }

    xSemaphoreTake(write_mutex, portMAX_DELAY);
    SemaphoreHandle_t completion = xSemaphoreCreateBinary();
    if (completion == nullptr)
    {
        xSemaphoreGive(write_mutex);
        return false;
    }

    print_job_t job = {
        .data = reinterpret_cast<const uint8_t *>(data),
        .length = length,
        .completion = completion,
        .result = ESP_FAIL,
    };
    print_job_t *job_pointer = &job;

    bool success = xQueueSend(print_queue, &job_pointer, portMAX_DELAY) ==
                   pdTRUE;
    if (success)
    {
        xSemaphoreTake(completion, portMAX_DELAY);
        success = job.result == ESP_OK;
    }

    vSemaphoreDelete(completion);
    xSemaphoreGive(write_mutex);
    return success;
}
