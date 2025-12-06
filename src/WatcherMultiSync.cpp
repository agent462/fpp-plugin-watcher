/*
 * WatcherMultiSync.cpp - MultiSync monitoring plugin for FPP Watcher
 *
 * Hooks into FPP's MultiSync system to collect real-time sync metrics
 * and detect potential issues across multi-sync hosts.
 */

#include "fpp-pch.h"

#include <algorithm>
#include <chrono>
#include <cmath>
#include <deque>
#include <fstream>
#include <mutex>
#include <string>
#include <unordered_map>
#include <sys/stat.h>
#include <sys/types.h>
#include <unistd.h>

#include <httpserver.hpp>
#include <jsoncpp/json/json.h>

#include "Plugin.h"
#include "Plugins.h"
#include "MultiSync.h"
#include "Sequence.h"
#include "log.h"
#include "common.h"
#include "settings.h"

// Configuration constants
static const size_t MAX_SYNC_EVENTS = 1000;      // Max events to keep per host
static const size_t MAX_PING_EVENTS = 100;       // Max ping events per host
static const int STALE_HOST_SECONDS = 30;        // Host considered stale after this
static const int MAX_FRAME_DRIFT = 5;            // Frames drift before flagging
static const double MAX_JITTER_MS = 50.0;        // Jitter threshold in ms
static const double MAX_PACKET_LOSS_PCT = 5.0;   // Packet loss threshold percent

// Sync event structure
struct SyncEvent {
    std::chrono::steady_clock::time_point timestamp;
    std::string filename;
    int frameNumber;
    float secondsElapsed;
    int localFrame;           // Local frame at time of receive
    int frameDrift;           // Difference from expected
};

// Ping event structure
struct PingEvent {
    std::chrono::steady_clock::time_point timestamp;
    double latencyMs;         // Round-trip time if available
};

// Issue types
enum class IssueType {
    NONE,
    SYNC_DRIFT,
    STALE_HOST,
    HIGH_JITTER,
    PACKET_LOSS,
    SEQUENCE_MISMATCH
};

static const char* IssueTypeToString(IssueType type) {
    switch (type) {
        case IssueType::SYNC_DRIFT: return "sync_drift";
        case IssueType::STALE_HOST: return "stale_host";
        case IssueType::HIGH_JITTER: return "high_jitter";
        case IssueType::PACKET_LOSS: return "packet_loss";
        case IssueType::SEQUENCE_MISMATCH: return "sequence_mismatch";
        default: return "none";
    }
}

// Issue structure
struct Issue {
    IssueType type;
    std::string description;
    std::chrono::steady_clock::time_point detectedAt;
    int severity;  // 1=info, 2=warning, 3=critical
};

// Per-host metrics
struct HostMetrics {
    std::string hostname;
    std::string ip;

    // Event buffers (circular)
    std::deque<SyncEvent> syncEvents;
    std::deque<PingEvent> pingEvents;

    // Running statistics
    int totalSyncPackets = 0;
    int totalPingPackets = 0;
    int missedPackets = 0;
    int errorPackets = 0;

    // Calculated metrics
    double avgFrameDrift = 0.0;
    double maxFrameDrift = 0.0;
    double avgJitterMs = 0.0;
    double packetLossPercent = 0.0;

    // Timing
    std::chrono::steady_clock::time_point lastSeen;
    std::chrono::steady_clock::time_point lastSyncPacket;

    // Current state
    std::string currentSequence;
    int lastExpectedFrame = -1;

    // Detected issues
    std::vector<Issue> activeIssues;

    HostMetrics() : lastSeen(std::chrono::steady_clock::now()),
                    lastSyncPacket(std::chrono::steady_clock::now()) {}
};

// Main plugin class
class WatcherMultiSyncPlugin : public FPPPlugin,
                                public MultiSyncPlugin,
                                public httpserver::http_resource {
public:
    WatcherMultiSyncPlugin()
        : FPPPlugin("fpp-plugin-watcher"),
          m_enabled(false),
          m_dataDir("/home/fpp/media/plugin-data/fpp-plugin-watcher/multisync/")
    {
        LogInfo(VB_PLUGIN, "WatcherMultiSync: Initializing multi-sync monitoring plugin\n");

        // Check if multi-sync is enabled
        if (!MultiSync::INSTANCE.isMultiSyncEnabled()) {
            LogInfo(VB_PLUGIN, "WatcherMultiSync: MultiSync not enabled, plugin will be passive\n");
        }

        // Register as a MultiSync plugin to receive callbacks
        MultiSync::INSTANCE.addMultiSyncPlugin(this);

        // Create data directory if needed
        CreateDirectoryIfMissing(m_dataDir);

        // Load any persisted state
        LoadState();

        m_enabled = true;
        LogInfo(VB_PLUGIN, "WatcherMultiSync: Plugin initialized successfully\n");
    }

    virtual ~WatcherMultiSyncPlugin() {
        LogInfo(VB_PLUGIN, "WatcherMultiSync: Shutting down\n");
        MultiSync::INSTANCE.removeMultiSyncPlugin(this);
        SaveState();
    }

    // ========== MultiSyncPlugin Interface - SEND (Player/Master mode) ==========

    virtual void SendSeqOpenPacket(const std::string& filename) override {
        if (!m_enabled) return;
        std::lock_guard<std::mutex> lock(m_mutex);
        m_currentMasterSequence = filename;
        m_seqOpenCount++;
        m_totalSyncPacketsSent++;
        m_lastSyncTime = std::chrono::steady_clock::now();
    }

    virtual void SendSeqSyncStartPacket(const std::string& filename) override {
        if (!m_enabled) return;
        std::lock_guard<std::mutex> lock(m_mutex);
        m_currentMasterSequence = filename;
        m_sequencePlaying = true;
        m_masterStartTime = std::chrono::steady_clock::now();
        m_seqStartCount++;
        m_totalSyncPacketsSent++;
        m_lastSyncTime = std::chrono::steady_clock::now();
    }

    virtual void SendSeqSyncStopPacket(const std::string& filename) override {
        if (!m_enabled) return;
        std::lock_guard<std::mutex> lock(m_mutex);
        m_sequencePlaying = false;
        if (m_currentMasterSequence == filename) {
            m_currentMasterSequence.clear();
        }
        m_seqStopCount++;
        m_totalSyncPacketsSent++;
        m_lastSyncTime = std::chrono::steady_clock::now();
    }

    virtual void SendSeqSyncPacket(const std::string& filename, int frames, float seconds) override {
        if (!m_enabled) return;
        std::lock_guard<std::mutex> lock(m_mutex);
        m_currentMasterSequence = filename;
        m_lastMasterFrame = frames;
        m_lastMasterSeconds = seconds;
        m_totalSyncPacketsSent++;
        m_lastSyncTime = std::chrono::steady_clock::now();
    }

    virtual void SendMediaOpenPacket(const std::string& filename) override {
        if (!m_enabled) return;
        std::lock_guard<std::mutex> lock(m_mutex);
        m_currentMediaFile = filename;
        m_mediaOpenCount++;
        m_totalMediaSyncPacketsSent++;
        m_lastSyncTime = std::chrono::steady_clock::now();
    }

    virtual void SendMediaSyncStartPacket(const std::string& filename) override {
        if (!m_enabled) return;
        std::lock_guard<std::mutex> lock(m_mutex);
        m_currentMediaFile = filename;
        m_mediaPlaying = true;
        m_mediaStartCount++;
        m_totalMediaSyncPacketsSent++;
        m_lastSyncTime = std::chrono::steady_clock::now();
    }

    virtual void SendMediaSyncStopPacket(const std::string& filename) override {
        if (!m_enabled) return;
        std::lock_guard<std::mutex> lock(m_mutex);
        m_mediaPlaying = false;
        if (m_currentMediaFile == filename) {
            m_currentMediaFile.clear();
        }
        m_mediaStopCount++;
        m_totalMediaSyncPacketsSent++;
        m_lastSyncTime = std::chrono::steady_clock::now();
    }

    virtual void SendMediaSyncPacket(const std::string& filename, float seconds) override {
        if (!m_enabled) return;
        std::lock_guard<std::mutex> lock(m_mutex);
        m_totalMediaSyncPacketsSent++;
        m_lastSyncTime = std::chrono::steady_clock::now();
    }

    virtual void SendBlankingDataPacket(void) override {
        if (!m_enabled) return;
        std::lock_guard<std::mutex> lock(m_mutex);
        m_totalBlankPacketsSent++;
        m_lastSyncTime = std::chrono::steady_clock::now();
    }

    virtual void SendPluginData(const std::string& name, const uint8_t* data, int len) override {
        if (!m_enabled) return;
        std::lock_guard<std::mutex> lock(m_mutex);
        m_totalPluginPacketsSent++;
    }

    virtual void SendFPPCommandPacket(const std::string& host, const std::string& cmd,
                                       const std::vector<std::string>& args) override {
        if (!m_enabled) return;
        std::lock_guard<std::mutex> lock(m_mutex);
        m_totalCommandPacketsSent++;
    }

    // ========== MultiSyncPlugin Interface - RECEIVE (Remote mode) ==========

    virtual void ReceivedSeqOpenPacket(const std::string& filename) override {
        if (!m_enabled) return;
        std::lock_guard<std::mutex> lock(m_mutex);
        m_currentMasterSequence = filename;
        m_seqOpenCount++;
        m_totalSyncPacketsReceived++;
        m_lastSyncTime = std::chrono::steady_clock::now();
    }

    virtual void ReceivedSeqSyncStartPacket(const std::string& filename) override {
        if (!m_enabled) return;
        std::lock_guard<std::mutex> lock(m_mutex);
        m_currentMasterSequence = filename;
        m_sequencePlaying = true;
        m_masterStartTime = std::chrono::steady_clock::now();
        m_seqStartCount++;
        m_totalSyncPacketsReceived++;
        m_lastSyncTime = std::chrono::steady_clock::now();
    }

    virtual void ReceivedSeqSyncStopPacket(const std::string& filename) override {
        if (!m_enabled) return;
        std::lock_guard<std::mutex> lock(m_mutex);
        m_sequencePlaying = false;
        if (m_currentMasterSequence == filename) {
            m_currentMasterSequence.clear();
        }
        m_seqStopCount++;
        m_totalSyncPacketsReceived++;
        m_lastSyncTime = std::chrono::steady_clock::now();
    }

    virtual void ReceivedSeqSyncPacket(const std::string& filename,
                                        int frames, float seconds) override {
        if (!m_enabled) return;
        auto now = std::chrono::steady_clock::now();
        std::lock_guard<std::mutex> lock(m_mutex);

        m_currentMasterSequence = filename;
        m_lastMasterFrame = frames;
        m_lastMasterSeconds = seconds;

        // Calculate frame drift if playing the same sequence
        int localFrame = -1;
        if (sequence && sequence->IsSequenceRunning(filename)) {
            localFrame = sequence->m_seqMSRemaining > 0 ?
                (int)((sequence->m_seqMSDuration - sequence->m_seqMSRemaining) / sequence->GetSeqStepTime()) : 0;
        }
        int frameDrift = (localFrame >= 0) ? (localFrame - frames) : 0;

        m_frameDriftSum += std::abs(frameDrift);
        m_frameDriftSamples++;
        if (std::abs(frameDrift) > m_maxFrameDrift) {
            m_maxFrameDrift = std::abs(frameDrift);
        }

        m_totalSyncPacketsReceived++;
        m_lastSyncTime = now;
    }

    virtual void ReceivedMediaOpenPacket(const std::string& filename) override {
        if (!m_enabled) return;
        std::lock_guard<std::mutex> lock(m_mutex);
        m_currentMediaFile = filename;
        m_mediaOpenCount++;
        m_totalMediaSyncPacketsReceived++;
        m_lastSyncTime = std::chrono::steady_clock::now();
    }

    virtual void ReceivedMediaSyncStartPacket(const std::string& filename) override {
        if (!m_enabled) return;
        std::lock_guard<std::mutex> lock(m_mutex);
        m_currentMediaFile = filename;
        m_mediaPlaying = true;
        m_mediaStartCount++;
        m_totalMediaSyncPacketsReceived++;
        m_lastSyncTime = std::chrono::steady_clock::now();
    }

    virtual void ReceivedMediaSyncStopPacket(const std::string& filename) override {
        if (!m_enabled) return;
        std::lock_guard<std::mutex> lock(m_mutex);
        m_mediaPlaying = false;
        if (m_currentMediaFile == filename) {
            m_currentMediaFile.clear();
        }
        m_mediaStopCount++;
        m_totalMediaSyncPacketsReceived++;
        m_lastSyncTime = std::chrono::steady_clock::now();
    }

    virtual void ReceivedMediaSyncPacket(const std::string& filename, float seconds) override {
        if (!m_enabled) return;
        std::lock_guard<std::mutex> lock(m_mutex);
        m_totalMediaSyncPacketsReceived++;
        m_lastSyncTime = std::chrono::steady_clock::now();
    }

    virtual void ReceivedBlankingDataPacket(void) override {
        if (!m_enabled) return;
        std::lock_guard<std::mutex> lock(m_mutex);
        m_totalBlankPacketsReceived++;
    }

    virtual void ReceivedPluginData(const std::string& name,
                                     const uint8_t* data, int len) override {
        if (!m_enabled) return;
        std::lock_guard<std::mutex> lock(m_mutex);
        m_totalPluginPacketsReceived++;
    }

    virtual void ReceivedFPPCommandPacket(const std::string& cmd,
                                           const std::vector<std::string>& args) override {
        if (!m_enabled) return;
        std::lock_guard<std::mutex> lock(m_mutex);
        m_totalCommandPacketsReceived++;
    }

    // ========== HTTP API ==========

    virtual HTTP_RESPONSE_CONST std::shared_ptr<httpserver::http_response>
    render_GET(const httpserver::http_request& req) override {
        std::string path(req.get_path());
        Json::Value result;

        if (path == "/fpp-plugin-watcher/multisync/metrics") {
            result = GetAllMetrics();
        } else if (path == "/fpp-plugin-watcher/multisync/issues") {
            result = GetActiveIssues();
        } else if (path == "/fpp-plugin-watcher/multisync/status") {
            result = GetStatus();
        } else if (path.find("/fpp-plugin-watcher/multisync/host/") == 0) {
            std::string host = path.substr(strlen("/fpp-plugin-watcher/multisync/host/"));
            result = GetHostMetrics(host);
        } else {
            result["error"] = "Unknown endpoint";
            std::string json = SaveJsonToString(result);
            return std::shared_ptr<httpserver::http_response>(
                new httpserver::string_response(json, 404, "application/json"));
        }

        std::string json = SaveJsonToString(result);
        return std::shared_ptr<httpserver::http_response>(
            new httpserver::string_response(json, 200, "application/json"));
    }

    virtual HTTP_RESPONSE_CONST std::shared_ptr<httpserver::http_response>
    render_POST(const httpserver::http_request& req) override {
        std::string path(req.get_path());
        Json::Value result;

        if (path == "/fpp-plugin-watcher/multisync/reset") {
            ResetMetrics();
            result["status"] = "ok";
            result["message"] = "Metrics reset";
        } else {
            result["error"] = "Unknown endpoint";
            std::string json = SaveJsonToString(result);
            return std::shared_ptr<httpserver::http_response>(
                new httpserver::string_response(json, 404, "application/json"));
        }

        std::string json = SaveJsonToString(result);
        return std::shared_ptr<httpserver::http_response>(
            new httpserver::string_response(json, 200, "application/json"));
    }

    // ========== APIProviderPlugin Interface ==========

    virtual void registerApis(httpserver::webserver* ws) override {
        LogInfo(VB_PLUGIN, "WatcherMultiSync: Registering API endpoints\n");
        ws->register_resource("/fpp-plugin-watcher/multisync/metrics", this);
        ws->register_resource("/fpp-plugin-watcher/multisync/issues", this);
        ws->register_resource("/fpp-plugin-watcher/multisync/status", this);
        ws->register_resource("/fpp-plugin-watcher/multisync/host", this, true);
        ws->register_resource("/fpp-plugin-watcher/multisync/reset", this);
    }

    virtual void unregisterApis(httpserver::webserver* ws) override {
        LogInfo(VB_PLUGIN, "WatcherMultiSync: Unregistering API endpoints\n");
        ws->unregister_resource("/fpp-plugin-watcher/multisync/metrics");
        ws->unregister_resource("/fpp-plugin-watcher/multisync/issues");
        ws->unregister_resource("/fpp-plugin-watcher/multisync/status");
        ws->unregister_resource("/fpp-plugin-watcher/multisync/host");
        ws->unregister_resource("/fpp-plugin-watcher/multisync/reset");
    }

private:
    // ========== Internal Methods ==========

    Json::Value GetStatus() {
        std::lock_guard<std::mutex> lock(m_mutex);
        Json::Value result;

        result["enabled"] = m_enabled;
        result["multiSyncEnabled"] = MultiSync::INSTANCE.isMultiSyncEnabled();
        result["currentMasterSequence"] = m_currentMasterSequence;
        result["sequencePlaying"] = m_sequencePlaying;
        result["currentMediaFile"] = m_currentMediaFile;
        result["mediaPlaying"] = m_mediaPlaying;
        result["lastMasterFrame"] = m_lastMasterFrame;
        result["lastMasterSeconds"] = m_lastMasterSeconds;

        // Lifecycle event counts
        Json::Value lifecycle;
        lifecycle["seqOpen"] = m_seqOpenCount;
        lifecycle["seqStart"] = m_seqStartCount;
        lifecycle["seqStop"] = m_seqStopCount;
        lifecycle["mediaOpen"] = m_mediaOpenCount;
        lifecycle["mediaStart"] = m_mediaStartCount;
        lifecycle["mediaStop"] = m_mediaStopCount;
        result["lifecycle"] = lifecycle;

        // Packet counts - SENT (Player/Master mode)
        Json::Value packetsSent;
        packetsSent["sync"] = m_totalSyncPacketsSent;
        packetsSent["mediaSync"] = m_totalMediaSyncPacketsSent;
        packetsSent["blank"] = m_totalBlankPacketsSent;
        packetsSent["plugin"] = m_totalPluginPacketsSent;
        packetsSent["command"] = m_totalCommandPacketsSent;
        result["packetsSent"] = packetsSent;

        // Packet counts - RECEIVED (Remote mode)
        Json::Value packetsReceived;
        packetsReceived["sync"] = m_totalSyncPacketsReceived;
        packetsReceived["mediaSync"] = m_totalMediaSyncPacketsReceived;
        packetsReceived["blank"] = m_totalBlankPacketsReceived;
        packetsReceived["plugin"] = m_totalPluginPacketsReceived;
        packetsReceived["command"] = m_totalCommandPacketsReceived;
        result["packetsReceived"] = packetsReceived;

        // Combined totals for easy display
        result["totalPacketsSent"] = m_totalSyncPacketsSent + m_totalMediaSyncPacketsSent +
                                     m_totalBlankPacketsSent + m_totalPluginPacketsSent +
                                     m_totalCommandPacketsSent;
        result["totalPacketsReceived"] = m_totalSyncPacketsReceived + m_totalMediaSyncPacketsReceived +
                                         m_totalBlankPacketsReceived + m_totalPluginPacketsReceived +
                                         m_totalCommandPacketsReceived;

        // Drift stats
        if (m_frameDriftSamples > 0) {
            result["avgFrameDrift"] = m_frameDriftSum / m_frameDriftSamples;
            result["maxFrameDrift"] = m_maxFrameDrift;
        }

        // Time since last sync
        auto now = std::chrono::steady_clock::now();
        auto elapsed = std::chrono::duration_cast<std::chrono::seconds>(now - m_lastSyncTime).count();
        result["secondsSinceLastSync"] = (int)elapsed;

        return result;
    }

    Json::Value GetAllMetrics() {
        std::lock_guard<std::mutex> lock(m_mutex);
        Json::Value result;

        // Get FPP's built-in sync stats
        Json::Value fppStats = MultiSync::INSTANCE.GetSyncStats();
        result["fppStats"] = fppStats;

        // Add our enhanced metrics
        result["status"] = GetStatus();

        // Add per-host data from our tracking
        Json::Value hosts(Json::arrayValue);
        for (const auto& pair : m_hostMetrics) {
            hosts.append(HostMetricsToJson(pair.second));
        }
        result["hosts"] = hosts;

        return result;
    }

    Json::Value GetHostMetrics(const std::string& hostOrIp) {
        std::lock_guard<std::mutex> lock(m_mutex);

        auto it = m_hostMetrics.find(hostOrIp);
        if (it != m_hostMetrics.end()) {
            return HostMetricsToJson(it->second);
        }

        // Try finding by hostname
        for (const auto& pair : m_hostMetrics) {
            if (pair.second.hostname == hostOrIp) {
                return HostMetricsToJson(pair.second);
            }
        }

        Json::Value result;
        result["error"] = "Host not found";
        return result;
    }

    Json::Value GetActiveIssues() {
        std::lock_guard<std::mutex> lock(m_mutex);
        Json::Value result;
        Json::Value issues(Json::arrayValue);

        auto now = std::chrono::steady_clock::now();

        // Check for stale sync
        auto elapsed = std::chrono::duration_cast<std::chrono::seconds>(now - m_lastSyncTime).count();
        if (m_totalSyncPacketsReceived > 0 && elapsed > STALE_HOST_SECONDS) {
            Json::Value issue;
            issue["type"] = "no_sync_packets";
            issue["description"] = "No sync packets received for " + std::to_string(elapsed) + " seconds";
            issue["severity"] = 2;
            issues.append(issue);
        }

        // Check drift (use average, not max - max can spike on FPP restart)
        double avgDrift = m_frameDriftSamples > 0 ? (m_frameDriftSum / m_frameDriftSamples) : 0.0;
        if (m_frameDriftSamples > 0 && avgDrift > MAX_FRAME_DRIFT) {
            Json::Value issue;
            issue["type"] = "sync_drift";
            char buf[64];
            snprintf(buf, sizeof(buf), "Average frame drift of %.1f frames detected", avgDrift);
            issue["description"] = buf;
            issue["severity"] = avgDrift > MAX_FRAME_DRIFT * 2 ? 3 : 2;
            issue["avgDrift"] = avgDrift;
            issue["maxDrift"] = m_maxFrameDrift;
            issues.append(issue);
        }

        // Per-host issues
        for (const auto& pair : m_hostMetrics) {
            for (const auto& issue : pair.second.activeIssues) {
                Json::Value issueJson;
                issueJson["type"] = IssueTypeToString(issue.type);
                issueJson["description"] = issue.description;
                issueJson["severity"] = issue.severity;
                issueJson["host"] = pair.second.hostname.empty() ? pair.first : pair.second.hostname;
                issues.append(issueJson);
            }
        }

        result["issues"] = issues;
        result["count"] = issues.size();

        return result;
    }

    void ResetMetrics() {
        std::lock_guard<std::mutex> lock(m_mutex);

        // Reset received counts
        m_totalSyncPacketsReceived = 0;
        m_totalMediaSyncPacketsReceived = 0;
        m_totalBlankPacketsReceived = 0;
        m_totalPluginPacketsReceived = 0;
        m_totalCommandPacketsReceived = 0;

        // Reset sent counts
        m_totalSyncPacketsSent = 0;
        m_totalMediaSyncPacketsSent = 0;
        m_totalBlankPacketsSent = 0;
        m_totalPluginPacketsSent = 0;
        m_totalCommandPacketsSent = 0;

        // Reset lifecycle counts
        m_seqOpenCount = 0;
        m_seqStartCount = 0;
        m_seqStopCount = 0;
        m_mediaOpenCount = 0;
        m_mediaStartCount = 0;
        m_mediaStopCount = 0;

        m_frameDriftSum = 0;
        m_frameDriftSamples = 0;
        m_maxFrameDrift = 0;

        m_hostMetrics.clear();

        LogInfo(VB_PLUGIN, "WatcherMultiSync: Metrics reset\n");
    }

    Json::Value HostMetricsToJson(const HostMetrics& metrics) {
        Json::Value result;
        result["ip"] = metrics.ip;
        result["hostname"] = metrics.hostname;
        result["totalSyncPackets"] = metrics.totalSyncPackets;
        result["totalPingPackets"] = metrics.totalPingPackets;
        result["avgFrameDrift"] = metrics.avgFrameDrift;
        result["maxFrameDrift"] = metrics.maxFrameDrift;
        result["avgJitterMs"] = metrics.avgJitterMs;
        result["packetLossPercent"] = metrics.packetLossPercent;
        result["currentSequence"] = metrics.currentSequence;

        // Last seen time
        auto now = std::chrono::steady_clock::now();
        auto elapsed = std::chrono::duration_cast<std::chrono::seconds>(now - metrics.lastSeen).count();
        result["secondsSinceLastSeen"] = (int)elapsed;

        // Issues
        Json::Value issues(Json::arrayValue);
        for (const auto& issue : metrics.activeIssues) {
            Json::Value issueJson;
            issueJson["type"] = IssueTypeToString(issue.type);
            issueJson["description"] = issue.description;
            issueJson["severity"] = issue.severity;
            issues.append(issueJson);
        }
        result["issues"] = issues;

        return result;
    }

    void CreateDirectoryIfMissing(const std::string& path) {
        struct stat st;
        if (stat(path.c_str(), &st) != 0) {
            mkdir(path.c_str(), 0755);
            // Set ownership to fpp user
            chown(path.c_str(), 1000, 1000);
        }
    }

    void LoadState() {
        std::string statePath = m_dataDir + "state.json";
        if (FileExists(statePath)) {
            Json::Value state;
            if (LoadJsonFromFile(statePath, state)) {
                m_totalSyncPacketsReceived = state.get("totalSyncPackets", 0).asInt();
                m_totalMediaSyncPacketsReceived = state.get("totalMediaSyncPackets", 0).asInt();
                LogInfo(VB_PLUGIN, "WatcherMultiSync: Loaded previous state\n");
            }
        }
    }

    void SaveState() {
        std::lock_guard<std::mutex> lock(m_mutex);

        Json::Value state;
        state["totalSyncPackets"] = m_totalSyncPacketsReceived;
        state["totalMediaSyncPackets"] = m_totalMediaSyncPacketsReceived;
        state["totalBlankPackets"] = m_totalBlankPacketsReceived;
        state["totalPluginPackets"] = m_totalPluginPacketsReceived;
        state["totalCommandPackets"] = m_totalCommandPacketsReceived;

        std::string statePath = m_dataDir + "state.json";
        SaveJsonToFile(state, statePath);

        // Ensure fpp ownership
        chown(statePath.c_str(), 1000, 1000);
    }

    // ========== Member Variables ==========

    std::mutex m_mutex;
    bool m_enabled;
    std::string m_dataDir;

    // Master tracking
    std::string m_currentMasterSequence;
    int m_lastMasterFrame = 0;
    float m_lastMasterSeconds = 0.0f;
    std::chrono::steady_clock::time_point m_masterStartTime;
    std::chrono::steady_clock::time_point m_lastSyncTime;
    bool m_sequencePlaying = false;
    std::string m_currentMediaFile;
    bool m_mediaPlaying = false;

    // Lifecycle event counts (Open/Start/Stop)
    int m_seqOpenCount = 0;
    int m_seqStartCount = 0;
    int m_seqStopCount = 0;
    int m_mediaOpenCount = 0;
    int m_mediaStartCount = 0;
    int m_mediaStopCount = 0;

    // Aggregate packet counts - RECEIVED (Remote mode)
    int m_totalSyncPacketsReceived = 0;
    int m_totalMediaSyncPacketsReceived = 0;
    int m_totalBlankPacketsReceived = 0;
    int m_totalPluginPacketsReceived = 0;
    int m_totalCommandPacketsReceived = 0;

    // Aggregate packet counts - SENT (Player/Master mode)
    int m_totalSyncPacketsSent = 0;
    int m_totalMediaSyncPacketsSent = 0;
    int m_totalBlankPacketsSent = 0;
    int m_totalPluginPacketsSent = 0;
    int m_totalCommandPacketsSent = 0;

    // Drift statistics
    double m_frameDriftSum = 0;
    int m_frameDriftSamples = 0;
    int m_maxFrameDrift = 0;

    // Per-host metrics (keyed by IP)
    std::unordered_map<std::string, HostMetrics> m_hostMetrics;
};

// Plugin entry point
extern "C" {
    FPPPlugins::Plugin* createPlugin() {
        return new WatcherMultiSyncPlugin();
    }
}
