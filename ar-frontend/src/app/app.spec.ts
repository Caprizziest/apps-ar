import { TestBed } from '@angular/core/testing';
import { App } from './app';
import { NavigationService } from './core/services/navigation.service';

describe('App', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [App],
    }).compileComponents();
  });

  it('should create the app', () => {
    const fixture = TestBed.createComponent(App);
    const app = fixture.componentInstance;
    expect(app).toBeTruthy();
  });

  it('should initialize with default v3 design mode', () => {
    const fixture = TestBed.createComponent(App);
    const app = fixture.componentInstance;
    expect(app.designMode()).toBe('v3');
  });

  it('should toggle dark mode and synchronize data-bs-theme attribute and localStorage', () => {
    const navService = TestBed.inject(NavigationService);
    const initialMode = navService.isDarkMode();

    navService.toggleDarkMode();
    expect(navService.isDarkMode()).toBe(!initialMode);
    expect(document.documentElement.getAttribute('data-bs-theme')).toBe(!initialMode ? 'dark' : 'light');
    expect(document.body.classList.contains('dark-mode')).toBe(!initialMode);
    expect(localStorage.getItem('ar_dark_mode')).toBe(String(!initialMode));

    // Toggle back
    navService.toggleDarkMode();
    expect(navService.isDarkMode()).toBe(initialMode);
    expect(document.documentElement.getAttribute('data-bs-theme')).toBe(initialMode ? 'dark' : 'light');
    expect(document.body.classList.contains('dark-mode')).toBe(initialMode);
    expect(localStorage.getItem('ar_dark_mode')).toBe(String(initialMode));
  });
});
