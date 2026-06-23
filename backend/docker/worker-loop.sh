#!/bin/sh

set -eu

LAST_MINUTE=""

run_every_minute() {
  php /var/www/hdreams-backend/workers/ProcessLeadAds.php >> /var/log/hdreams-leads.log 2>&1 || true
}

run_every_five_minutes() {
  php /var/www/hdreams-backend/workers/PublishFacebookPost.php >> /var/log/hdreams-posts.log 2>&1 || true
  php /var/www/hdreams-backend/workers/WorkflowAutomationWorker.php >> /var/log/hdreams-workflow.log 2>&1 || true
}

run_every_ten_minutes() {
  php /var/www/hdreams-backend/workers/SlaAlertWorker.php >> /var/log/hdreams-sla-alerts.log 2>&1 || true
  php /var/www/hdreams-backend/workers/AutoAssignWorker.php >> /var/log/hdreams-auto-assign.log 2>&1 || true
}

run_every_six_hours() {
  php /var/www/hdreams-backend/workers/RecalculateScores.php >> /var/log/hdreams-scoring.log 2>&1 || true
}

while true; do
  CURRENT_MINUTE="$(date '+%Y-%m-%d %H:%M')"

  if [ "$CURRENT_MINUTE" != "$LAST_MINUTE" ]; then
    MINUTE="$(date '+%M')"
    HOUR="$(date '+%H')"

    run_every_minute

    if [ $((10#$MINUTE % 5)) -eq 0 ]; then
      run_every_five_minutes
    fi

    if [ $((10#$MINUTE % 10)) -eq 0 ]; then
      run_every_ten_minutes
    fi

    if [ "$MINUTE" = "00" ] && [ $((10#$HOUR % 6)) -eq 0 ]; then
      run_every_six_hours
    fi

    LAST_MINUTE="$CURRENT_MINUTE"
  fi

  sleep 5
done
