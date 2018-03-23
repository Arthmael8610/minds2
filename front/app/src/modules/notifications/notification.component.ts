import { Component } from '@angular/core';
import { SessionFactory } from '../../services/session';
import { Reason, rejectionReasons } from '../../controllers/admin/boosts/rejection-reasons';

@Component({
  moduleId: module.id,
  selector: 'minds-notification',
  inputs: ['_notification: notification'],
  templateUrl: 'notification.component.html'
})
export class NotificationComponent {

  notification: any;
  session = SessionFactory.build();

  set _notification(value: any) {
    this.notification = value;
  }

  openMessengerWindow(event) {
    if (event) {
      event.preventDefault();
    }

    (<any>window).openMessengerWindow();
  }

  findReason(code: number): Reason {
    return rejectionReasons.find((item: Reason) => {
      return item.code === code;
    });
  }

}
